<?php

namespace App\Shared\Logging;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * before_send / before_breadcrumb for Sentry (config/sentry.php).
 *
 * Error reports leave the platform, so they get the strictest treatment:
 * request bodies and cookies are always dropped (they may hold clinical or
 * HMO data), headers, query strings, extra data, exception messages and
 * breadcrumbs go through the same redaction as the logs, and no personal
 * data is attached (send_default_pii stays false).
 */
final class SentryScrubber
{
    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        $request = $event->getRequest();

        if ($request !== []) {
            unset($request['data'], $request['cookies']);

            if (isset($request['headers']) && is_array($request['headers'])) {
                $request['headers'] = RedactSensitiveData::scrubArray($request['headers']);
            }

            foreach (['query_string', 'url'] as $field) {
                if (isset($request[$field]) && is_string($request[$field])) {
                    $request[$field] = RedactSensitiveData::scrubMessage($request[$field]);
                }
            }

            $event->setRequest($request);
        }

        $event->setExtra(RedactSensitiveData::scrubArray($event->getExtra()));

        if ($event->getMessage() !== null) {
            $event->setMessage(RedactSensitiveData::scrubMessage($event->getMessage()), $event->getMessageParams());
        }

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(RedactSensitiveData::scrubMessage($exception->getValue()));
        }

        return $event;
    }

    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        if ($breadcrumb->getMessage() !== null) {
            $breadcrumb = $breadcrumb->withMessage(RedactSensitiveData::scrubMessage($breadcrumb->getMessage()));
        }

        foreach (RedactSensitiveData::scrubArray($breadcrumb->getMetadata()) as $name => $value) {
            $breadcrumb = $breadcrumb->withMetadata((string) $name, $value);
        }

        return $breadcrumb;
    }
}
