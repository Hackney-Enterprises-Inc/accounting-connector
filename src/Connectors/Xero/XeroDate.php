<?php

declare(strict_types=1);

namespace Hei\AccountingConnector\Connectors\Xero;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Xero's two date formats, both of which you will meet.
 *
 * Requests take a plain ISO date. Responses hand back Microsoft JSON dates, which
 * look like `/Date(1518685950940+0000)/`: milliseconds since the epoch, optionally
 * with a UTC offset, wrapped in a literal `/Date(...)/`. The official SDK hides this
 * inside its deserialiser, so it is easy to forget it exists until a response date
 * is parsed as the string it literally is and every transaction lands in 1970.
 */
final class XeroDate
{
    /**
     * Format for sending. Xero accepts a plain ISO 8601 date on writes.
     */
    public static function toXero(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    /**
     * Format a full timestamp, for the few endpoints that want one.
     */
    public static function toXeroDateTime(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:s');
    }

    /**
     * Parse whatever Xero sent back.
     *
     * Handles the /Date(...)/ form, plain ISO dates, and anything else strtotime
     * understands. Returns null rather than throwing on an unparseable value: a
     * date we cannot read is not a reason to fail a sync that already posted.
     */
    public static function parse(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (preg_match('#^/Date\((-?\d+)([+-]\d{4})?\)/$#', $value, $matches) === 1) {
            $milliseconds = (int) $matches[1];

            return (new DateTimeImmutable)->setTimestamp(intdiv($milliseconds, 1000));
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Parse a calendar date, such as a lock date, as midnight UTC on that day.
     *
     * Stricter than parse(): only the /Date(...)/ form and an ISO date (optionally
     * with a time, which is ignored) are accepted, and the day must exist. The day
     * is taken in UTC, which is how Xero encodes a date-only field, rather than in
     * the host's default timezone, where midnight UTC can already be the day before.
     * Returns null for anything else; the caller decides whether that is an error.
     */
    public static function parseDate(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $utc = new DateTimeZone('UTC');

        if (preg_match('#^/Date\((-?\d+)([+-]\d{4})?\)/$#', $value, $matches) === 1) {
            $day = (new DateTimeImmutable('@'.intdiv((int) $matches[1], 1000)))->setTimezone($utc)->format('Y-m-d');
        } elseif (preg_match('#^(\d{4}-\d{2}-\d{2})(T\d{2}:\d{2}:\d{2}(\.\d+)?)?$#', $value, $matches) === 1) {
            $day = $matches[1];
        } else {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, $utc);

        // createFromFormat rolls 2018-02-30 over to March; a day that does not
        // exist is not a date Xero meant.
        return $date !== false && $date->format('Y-m-d') === $day ? $date : null;
    }
}
