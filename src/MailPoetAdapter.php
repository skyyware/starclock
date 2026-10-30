<?php
declare(strict_types=1);

namespace Starclock;

use MailPoet\API\JSON\SuccessResponse;
use MailPoet\API\JSON\v1\SendingQueue;
use MailPoet\DI\ContainerWrapper;
use MailPoet\Entities\NewsletterEntity;
use MailPoet\Newsletter\NewsletterSaveController;
use MailPoet\Newsletter\NewslettersRepository;
use RuntimeException;

final class MailPoetAdapter implements Adapter
{
    public const TESTED_VERSIONS = ['5.35.1', '5.39.0', '5.44.0'];

    public function id(): string
    {
        return 'mailpoet';
    }

    public function label(): string
    {
        return 'MailPoet';
    }

    public function available(): void
    {
        if (!defined('MAILPOET_VERSION') || !class_exists(ContainerWrapper::class)) {
            throw new RuntimeException('MailPoet ist nicht aktiv. Der Zeitplan bleibt pausiert.');
        }
        if (!in_array(MAILPOET_VERSION, self::TESTED_VERSIONS, true)) {
            throw new RuntimeException('Diese MailPoet-Version ist noch nicht geprüft. Bitte Starclock aktualisieren oder die Kompatibilität prüfen lassen.');
        }
    }

    private function service(string $class): object
    {
        $this->available();
        return ContainerWrapper::getInstance()->get($class);
    }

    private function mail(int $id): NewsletterEntity
    {
        $repo = $this->service(NewslettersRepository::class);
        $mail = $repo->findOneById($id);
        if (!$mail) {
            throw new RuntimeException('Die gewählte Mail wurde nicht gefunden.');
        }
        $repo->refresh($mail);
        return $mail;
    }

    public function choices(): array
    {
        return array_map(static fn($mail): array => ['id' => (int)$mail->getId(), 'label' => (string)$mail->getSubject()],
            $this->service(NewslettersRepository::class)->findDraftByTypes(['standard']));
    }

    public static function fingerprint(NewsletterEntity $mail): string
    {
        $segments = array_map('intval', $mail->getSegmentIds());
        sort($segments);
        return hash('sha256', json_encode([
            $mail->getType(), $mail->getBody(), $mail->getSubject(), $mail->getPreheader(),
            $mail->getSenderName(), $mail->getSenderAddress(), $mail->getReplyToName(),
            $mail->getReplyToAddress(), $segments, $mail->getFilterSegmentId(),
        ], JSON_THROW_ON_ERROR));
    }

    public function inspect(int $templateId, bool $requireDraft = true): array
    {
        $mail = $this->mail($templateId);
        if ($mail->getType() !== 'standard' || ($requireDraft && $mail->getStatus() !== 'draft') || $mail->getDeletedAt()) {
            throw new RuntimeException('Bitte einen unveröffentlichten Standard-Newsletter als Vorlage wählen.');
        }
        if (!$mail->getSubject() || !is_email($mail->getSenderAddress()) || !is_email($mail->getReplyToAddress())) {
            throw new RuntimeException('Betreff, Absender und Antwortadresse müssen in MailPoet vollständig sein.');
        }
        if (!str_contains($mail->getContent(), '[link:subscription_unsubscribe')) {
            throw new RuntimeException('In der Vorlage fehlt der MailPoet-Abmeldelink.');
        }
        if ($mail->getFilterSegmentId()) {
            throw new RuntimeException('Bitte feste MailPoet-Listen ohne zusätzliches Filtersegment verwenden.');
        }
        $lists = [];
        foreach ($mail->getNewsletterSegments() as $membership) {
            $segment = $membership->getSegment();
            if (!$segment) {
                throw new RuntimeException('Eine Empfängerliste fehlt. Bitte die Vorlage prüfen.');
            }
            if ($segment->getDeletedAt() || $segment->getType() !== 'default') {
                throw new RuntimeException('Bitte aktive MailPoet-Listen verwenden.');
            }
            $lists[] = (string)$segment->getName();
        }
        if (!$lists) {
            throw new RuntimeException('In der Vorlage fehlt die Empfängerliste.');
        }
        return ['fingerprint' => self::fingerprint($mail), 'subject' => $mail->getSubject(),
            'sender' => $mail->getSenderName() . ' <' . $mail->getSenderAddress() . '>',
            'reply_to' => $mail->getReplyToAddress(), 'lists' => $lists];
    }

    public function duplicate(int $templateId): int
    {
        $master = $this->mail($templateId);
        $save = $this->service(NewsletterSaveController::class);
        $copy = $save->duplicate($master);
        $copy = $save->save(['id' => $copy->getId(), 'subject' => $master->getSubject()]);
        return (int)$copy->getId();
    }

    public function enqueue(int $copyId): string
    {
        $response = $this->service(SendingQueue::class)->add(['newsletter_id' => $copyId]);
        if (!$response instanceof SuccessResponse) {
            throw new RuntimeException('MailPoet hat die Übergabe nicht bestätigt. Den Lauf in MailPoet prüfen; er wird nicht automatisch wiederholt.');
        }
        $queue = $this->mail($copyId)->getLatestQueue();
        if (!$queue || !$queue->getTask()) {
            throw new RuntimeException('Der MailPoet-Versandbeleg fehlt. Den Lauf manuell prüfen.');
        }
        return (string)$queue->getTask()->getId();
    }

    public function pause(int $copyId): void
    {
        $mail = $this->mail($copyId);
        $queue = $mail->getLatestQueue();
        if (!$queue || in_array($queue->getTask()?->getStatus(), ['completed', 'cancelled', 'paused'], true)) {
            return;
        }
        if (!$this->service(SendingQueue::class)->pause(['newsletter_id' => $copyId]) instanceof SuccessResponse) {
            throw new RuntimeException('Der offene MailPoet-Lauf konnte nicht pausiert werden. Starclock aktiviert lassen und den Versand in MailPoet prüfen.');
        }
        if ($this->deliveryState($copyId) !== 'paused') {
            throw new RuntimeException('MailPoet bestätigt die Pause noch nicht. Starclock aktiviert lassen.');
        }
    }

    public function deliveryState(int $copyId): string
    {
        $queue = $this->mail($copyId)->getLatestQueue();
        if (!$queue) {
            return 'draft';
        }
        return match ($queue->getTask()?->getStatus()) {
            'completed' => 'completed', 'paused' => 'paused', 'cancelled' => 'cancelled',
            default => 'queued',
        };
    }
}
