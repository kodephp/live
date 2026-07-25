<?php

declare(strict_types=1);

namespace Kode\Live;

use Kode\Live\Contracts\LivePlatform;
use Kode\Live\Support\Enum\Platform;
use Kode\Live\Support\Exception\ConfigurationException;

/**
 * 直播平台驱动管理器（对外主入口 / 门面）。
 *
 * 支持直接注册已构建的驱动实例，或通过工厂闭包惰性构建；按平台标识取用。
 */
final class LiveManager
{
    /** @var array<string, LivePlatform> */
    private array $resolved = [];

    /** @var array<string, callable(): LivePlatform> */
    private array $factories = [];

    /**
     * 注册一个已构建好的驱动实例。
     */
    public function register(LivePlatform $platform): self
    {
        $this->resolved[$platform->name()->value] = $platform;

        return $this;
    }

    /**
     * 注册一个惰性驱动工厂（首次取用时才构建）。
     *
     * @param callable(): LivePlatform $factory
     */
    public function extend(Platform|string $platform, callable $factory): self
    {
        $this->factories[$this->key($platform)] = $factory;

        return $this;
    }

    public function has(Platform|string $platform): bool
    {
        $key = $this->key($platform);

        return isset($this->resolved[$key]) || isset($this->factories[$key]);
    }

    /**
     * 取得指定平台的驱动。
     */
    public function driver(Platform|string $platform): LivePlatform
    {
        $key = $this->key($platform);

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        if (isset($this->factories[$key])) {
            $instance = ($this->factories[$key])();
            $this->resolved[$key] = $instance;

            return $instance;
        }

        throw ConfigurationException::invalid('platform', \sprintf('未注册的平台驱动：%s', $key));
    }

    /**
     * @return list<string>
     */
    public function registered(): array
    {
        return array_values(array_unique([
            ...array_keys($this->resolved),
            ...array_keys($this->factories),
        ]));
    }

    private function key(Platform|string $platform): string
    {
        return $platform instanceof Platform ? $platform->value : $platform;
    }
}
