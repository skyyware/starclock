<?php
declare(strict_types=1);

namespace Starclock;

interface Adapter
{
    public function id(): string;
    public function label(): string;
    public function available(): void;
    public function choices(): array;
    public function inspect(int $templateId, bool $requireDraft = true): array;
    public function duplicate(int $templateId): int;
    public function enqueue(int $copyId): string;
    public function pause(int $copyId): void;
    public function deliveryState(int $copyId): string;
}
