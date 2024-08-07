<?php 
declare(strict_types=1);

namespace CustomStylesdrop\Cart\Checkout\Error;

use Shopware\Core\Checkout\Cart\Error\Error;

class CustomCartBlockedError extends Error
{
    private const KEY = 'custom-line-item-blocked';

    private string $lineItemId;

    public function __construct(string $lineItemId)
    {
        $this->lineItemId = $lineItemId;
        parent::__construct();
    }

    public function getId(): string
    {
        return $this->lineItemId;
    }

    public function getMessageKey(): string
    {
        return self::KEY;
    }

    public function getLevel(): int
    {
//        return self::LEVEL_NOTICE;
//        return self::LEVEL_WARNING;
        return self::LEVEL_ERROR;
    }

    public function blockOrder(): bool
    {
        return true;
    }

    public function getParameters(): array
    {
        return [ 'lineItemId' => $this->lineItemId ];
    }
}