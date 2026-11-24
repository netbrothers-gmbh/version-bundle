<?php

declare(strict_types=1);

/*
 * This file is part of the NetBrothers VersionBundle.
 *
 * (c) 2021 NetBrothers GmbH | Thilo Ratnaweera & Stefan Wessel (https://netbrothers.de)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NetBrothers\VersionBundle\Services\Sql;

/**
 * Generates SQL identifiers (version table names, trigger names, foreign key names)
 * guaranteed to fit within MySQL/MariaDB's 64-character identifier limit.
 */
final readonly class IdentifierHelper
{
    private const int HASH_LENGTH = 8;

    public function buildVersionTableName(string $originTableName): string
    {
        return $this->buildIdentifier($originTableName, Definitions::VERSION_TABLE_NAME_POSTFIX);
    }

    public function buildTriggerName(string $originTableName, string $triggerSuffix): string
    {
        return $this->buildIdentifier($originTableName, '_' . $triggerSuffix);
    }

    public function buildForeignKeyName(string $originTableName): string
    {
        return $this->buildIdentifier(
            $originTableName,
            Definitions::VERSION_TABLE_NAME_POSTFIX . Definitions::FK_POSTFIX,
        );
    }

    /**
     * Builds `<prefix><suffix>` within `$maxLength` characters.
     *
     * When `<prefix><suffix>` already fits, it is returned verbatim.
     * Otherwise, `<prefix>` is truncated and combined with a deterministic
     * 8-character SHA-256 hash suffix so collisions are avoided and the
     * generated identifier never exceeds `$maxLength` characters.
     */
    public function buildIdentifier(
        string $prefix,
        string $suffix,
        int $maxLength = Definitions::MAX_IDENTIFIER_LENGTH,
    ): string {
        $full = $prefix . $suffix;
        if (strlen($full) <= $maxLength) {
            return $full;
        }

        $hash = substr(hash('sha256', $full), 0, self::HASH_LENGTH);
        $reserved = strlen($suffix) + self::HASH_LENGTH + 1; // `_<hash><suffix>`
        $prefixLength = $maxLength - $reserved;

        if ($prefixLength <= 0) {
            return substr($hash . $suffix, 0, $maxLength);
        }

        $truncatedPrefix = rtrim(substr($prefix, 0, $prefixLength), '_');
        if ($truncatedPrefix === '') {
            return substr($hash . $suffix, 0, $maxLength);
        }

        return $truncatedPrefix . '_' . $hash . $suffix;
    }
}
