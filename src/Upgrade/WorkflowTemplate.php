<?php

namespace Gadya\Connect\Upgrade;

/**
 * Which version of Gadya's update workflow this site's repository holds,
 * from the first line of `.github/workflows/gadya-update.yml`:
 *
 *     # gadya-update-template: 2
 *
 * The portal compares it with the template it ships and offers to refresh
 * the file; a workflow file cannot be changed from CI.
 */
class WorkflowTemplate
{
    public const PATH = '.github/workflows/gadya-update.yml';

    /**
     * "2" for a file that says so, "1" for the first template (which said
     * nothing), null where there is no file at all.
     */
    public static function installed(?string $path = null): ?string
    {
        $path ??= base_path(self::PATH);

        if (! is_file($path)) {
            return null;
        }

        return self::number((string) @file_get_contents($path, length: 200)) ?? '1';
    }

    public static function number(string $workflow): ?string
    {
        return preg_match('/^#\s*gadya-update-template:\s*(\d+)/', ltrim($workflow), $matches) === 1 ? $matches[1] : null;
    }
}
