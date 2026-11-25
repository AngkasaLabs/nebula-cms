<?php

namespace App\Services;

use App\Models\Plugin;
use App\Support\AdminNavigation;
use Composer\Semver\Semver;
use InvalidArgumentException;

class PluginRequirementChecker
{
    /**
     * Validate declared requirements without throwing.
     *
     * @return array{ok: bool, errors: array<int, string>, warnings: array<int, string>}
     */
    public function check(Plugin $plugin): array
    {
        $errors = [];
        $warnings = [];

        $requires = $plugin->requires;
        if (! is_array($requires) || $requires === []) {
            $this->checkFrontendRuntime($plugin, $errors);
            $this->checkAdminNavigation($plugin, $warnings);

            return ['ok' => count($errors) === 0, 'errors' => $errors, 'warnings' => $warnings];
        }

        // Check CMS version constraint
        $cmsConstraint = $requires['cms_version'] ?? null;
        if (is_string($cmsConstraint) && $cmsConstraint !== '') {
            $cmsVersion = (string) config('nebula.version', '0.0.0');
            $error = $this->checkSemverSatisfies('NebulaCMS', $cmsVersion, $cmsConstraint);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        // Check PHP version constraint
        $phpConstraint = $requires['php'] ?? null;
        if (is_string($phpConstraint) && $phpConstraint !== '') {
            $error = $this->checkSemverSatisfies('PHP', PHP_VERSION, $phpConstraint);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        // Check plugin dependencies
        $depSlugs = $requires['plugins'] ?? [];
        if (is_array($depSlugs) && $depSlugs !== []) {
            foreach ($depSlugs as $slug) {
                if (! is_string($slug) || $slug === '') {
                    $warnings[] = 'Invalid entry in requires.plugins; expected non-empty strings.';

                    continue;
                }
                $other = Plugin::query()->where('slug', $slug)->first();
                if (! $other) {
                    $errors[] = "Required plugin \"{$slug}\" is not installed.";

                    continue;
                }
                if (! $other->is_active) {
                    $errors[] = "Required plugin \"{$slug}\" must be activated before this plugin can run.";
                }
            }
        }

        $this->checkFrontendRuntime($plugin, $errors);
        $this->checkAdminNavigation($plugin, $warnings);

        return ['ok' => count($errors) === 0, 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Warn about admin navigation metadata the sidebar cannot render.
     *
     * @param  array<int, string>  $warnings
     */
    protected function checkAdminNavigation(Plugin $plugin, array &$warnings): void
    {
        $items = $plugin->getAdminNavigationFromDisk();

        if ($items === []) {
            return;
        }

        $knownSections = array_keys(AdminNavigation::SECTIONS);
        $legacySections = array_keys(AdminNavigation::LEGACY_SECTIONS);

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $group = $item['group'] ?? null;
            if (is_string($group) && $group !== ''
                && ! in_array(strtolower($group), $knownSections, true)
                && ! in_array(strtolower($group), $legacySections, true)) {
                $warnings[] = "Unknown admin_navigation group \"{$group}\"; the sidebar shows it under \"Extensions\".";
            }

            $icon = $item['icon'] ?? null;
            if (is_string($icon) && $icon !== '' && ! in_array($icon, AdminNavigation::ICONS, true)) {
                $warnings[] = "Unknown admin_navigation icon \"{$icon}\"; the sidebar uses the default icon.";
            }
        }
    }

    /**
     * @param  array<int, string>  $errors
     */
    protected function checkFrontendRuntime(Plugin $plugin, array &$errors): void
    {
        if ($plugin->getFrontendTypeFromDisk() !== 'inertia-prebuilt') {
            return;
        }

        if ($plugin->getFrontendEntrypointsFromDisk() === []) {
            $errors[] = 'Plugin frontend entrypoints are missing or invalid.';
        }

        $manifestPath = $plugin->getFrontendManifestAbsolutePathFromDisk();
        if (! is_string($manifestPath) || $manifestPath === '' || ! is_file($manifestPath)) {
            $errors[] = 'Plugin frontend manifest is missing.';
        }
    }

    /**
     * Non-throwing version: returns error message or null.
     */
    protected function checkSemverSatisfies(string $label, string $version, string $constraint): ?string
    {
        try {
            $ok = Semver::satisfies($version, $constraint);
        } catch (\Throwable $e) {
            return "Invalid version or constraint for {$label}: ".$e->getMessage();
        }

        if (! $ok) {
            return "{$label} version {$version} does not satisfy constraint \"{$constraint}\".";
        }

        return null;
    }

    /**
     * @deprecated Use check() instead — this only surfaces the first error.
     *
     * @throws InvalidArgumentException
     */
    public function assertCompatible(Plugin $plugin): void
    {
        $result = $this->check($plugin);
        if (! $result['ok']) {
            throw new InvalidArgumentException(implode(' ', $result['errors']));
        }
    }
}
