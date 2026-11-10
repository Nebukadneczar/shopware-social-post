<?php declare(strict_types=1);

namespace SocialPost\Task;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/** Läuft alle 5 Minuten und sendet fällige geplante Beiträge. */
class SendDueTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'social_post.send_due';
    }

    public static function getDefaultInterval(): int
    {
        return 300;
    }
}
