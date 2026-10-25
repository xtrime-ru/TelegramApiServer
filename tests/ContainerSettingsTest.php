<?php

use danog\MadelineProto\Settings\RPC;
use PHPUnit\Framework\TestCase;

final class ContainerSettingsTest extends TestCase
{
    public function testContainerLimitPersistsAndCanBeResetOnSettingsUpdate(): void
    {
        $settings = new RPC;
        $settings->setContainerMaxSize(65536);
        $restored = unserialize(serialize($settings));
        self::assertSame(65536, $restored->getContainerMaxSize());

        $restored->merge(new RPC);
        self::assertSame(32768, $restored->getContainerMaxSize());
    }

    public function testInvalidContainerLimitIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RPC)->setContainerMaxSize(0);
    }
}
