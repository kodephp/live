<?php

declare(strict_types=1);

namespace Kode\Live\Transporter\Enum;

/**
 * SRT 连接模式。
 *
 * - Caller：主动向外发起连接（最常见的「推流端 → 摄入端」场景）。
 * - Listener：被动等待对端连接（摄入端常驻监听）。
 * - Rendezvous：双方同时发起，由先到达者胜出（NAT 穿透常用）。
 */
enum SrtMode: string
{
    case Caller = 'caller';
    case Listener = 'listener';
    case Rendezvous = 'rendezvous';
}
