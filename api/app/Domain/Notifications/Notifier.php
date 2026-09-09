<?php

namespace App\Domain\Notifications;

use App\Domain\Messaging\MessageDispatcher;
use App\Models\Notification;
use App\Models\TenantUser;
use App\Support\Access\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Raise a system notification.
 *
 * Called from the middle of other work — a refund, a send, a domain check — so it never throws.
 * Something worth telling the organiser about is never worth failing the thing that happened over.
 *
 * A `danger` notification is also emailed, to the people whose role carries the permission that
 * governs it. That is the difference between a notice and an alarm: a message a buyer did not
 * receive should not wait for somebody to open the panel.
 */
class Notifier
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly MessageDispatcher $dispatcher,
    ) {}

    public function raise(string $kind, array $params = [], ?object $subject = null): ?Notification
    {
        if (! NotificationKinds::exists($kind) || ! $this->tenants->has()) {
            return null;
        }

        try {
            $notification = Notification::create([
                'kind' => $kind,
                'level' => NotificationKinds::level($kind),
                'params' => $this->clean($params),
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject && method_exists($subject, 'getKey') ? $subject->getKey() : null,
                'subject_label' => $this->label($subject),
            ]);

            if ('danger' === $notification->level) {
                $this->email($notification);
            }

            return $notification;
        } catch (Throwable $e) {
            Log::warning('Notification could not be raised', ['kind' => $kind, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Raise this at most once per window.
     *
     * A provider that is refusing is refusing for every message it is handed; one notice saying so
     * is useful and forty are a reason to stop reading them.
     */
    public function raiseOnce(string $kind, string $key, array $params = [], int $seconds = 3600): ?Notification
    {
        $lock = 'notify:once:'.$this->tenants->id().':'.$kind.':'.sha1($key);

        if (! Cache::add($lock, true, $seconds)) {
            return null;
        }

        return $this->raise($kind, $params);
    }

    /* --------------------------------------------------------------------------- internals */

    /** Everyone in this account whose role carries the permission a kind is governed by. */
    private function email(Notification $notification): void
    {
        $permission = NotificationKinds::permission($notification->kind);

        if (! $permission) {
            return;
        }

        $locale = app()->getLocale();
        $variables = [
            'title' => __(NotificationKinds::key($notification->kind, 'title'), $notification->params, $locale),
            'body' => __(NotificationKinds::key($notification->kind, 'body'), $notification->params, $locale),
            'account' => (string) ($this->tenants->get()?->name ?? ''),
        ];

        foreach ($this->recipients($permission) as $address) {
            $this->dispatcher->send('system.notice', 'email', $address, $variables, $locale);
        }
    }

    /** @return list<string> */
    private function recipients(string $permission): array
    {
        $addresses = [];

        $members = TenantUser::with('user')
            ->whereNull('suspended_at')
            ->get()
            ->filter(fn (TenantUser $member) => in_array(
                $permission,
                $this->permissionsOf($member),
                true
            ));

        foreach ($members as $member) {
            $email = $member->user?->email;

            if ($email) {
                $addresses[$email] = $email;
            }
        }

        // Five is plenty: an alarm that goes to forty people is an alarm nobody owns.
        return array_slice(array_values($addresses), 0, 5);
    }

    /**
     * What a membership holds — the same resolution the Gate does, and for the same reason it is
     * written twice rather than shared: the Gate answers about a *request*, and there is no
     * request here. A role the organiser invented and has since deleted holds nothing.
     *
     * @return list<string>
     */
    private function permissionsOf(TenantUser $member): array
    {
        if (Permissions::isBuiltIn((string) $member->role)) {
            return Permissions::forRole((string) $member->role);
        }

        $role = \App\Models\TenantRole::where('key', $member->role)->first();

        return $role ? Permissions::sanitise((array) $role->permissions) : [];
    }

    /** Params are facts, and short ones: they end up inside a translated sentence. */
    private function clean(array $params): array
    {
        $clean = [];

        foreach ($params as $name => $value) {
            if (is_string($name) && (is_string($value) || is_numeric($value))) {
                $clean[$name] = mb_substr((string) $value, 0, 120);
            }
        }

        return $clean;
    }

    private function label(?object $subject): ?string
    {
        foreach (['name', 'external_order_id', 'hostname', 'subject'] as $field) {
            if (isset($subject?->{$field}) && is_string($subject->{$field})) {
                return mb_substr($subject->{$field}, 0, 190);
            }
        }

        return null;
    }
}
