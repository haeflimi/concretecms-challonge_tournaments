<?php

namespace ChallongeTournaments\Config;

use Concrete\Core\Config\Repository\Liaison;
use Concrete\Core\Package\PackageService;

/**
 * The package settings (config/settings.php, overridden by the dashboard page and application/config).
 */
class Settings
{
    const HANDLE = 'challonge_tournaments';

    /** @var Liaison */
    protected $config;

    public function __construct(PackageService $packages)
    {
        $this->config = $packages->getClass(self::HANDLE)->getFileConfig();
    }

    /**
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->config->get('settings.' . $key, $default);
    }

    public function save(string $key, $value): void
    {
        $this->config->save('settings.' . $key, $value);
    }

    public function getApiKey(): string
    {
        return trim((string) $this->get('api_key', ''));
    }

    public function usesFakeClient(): bool
    {
        return (bool) $this->get('fake_client', false) || $this->getApiKey() === '';
    }

    public function getCommunity(): string
    {
        return trim((string) $this->get('community', ''));
    }

    public function getSyncIntervalMinutes(): int
    {
        return max(1, (int) $this->get('sync_interval_minutes', 10));
    }

    public function getStaleAfterMinutes(): int
    {
        return max(0, (int) $this->get('stale_after_minutes', 30));
    }

    public function getEmbedTheme(): string
    {
        return trim((string) $this->get('embed_theme', ''));
    }

    public function getEmbedMultiplier(): string
    {
        $value = (float) $this->get('embed_multiplier', 1.0);

        return $value > 0 ? rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') : '1';
    }

    public function getAutoConfirmMinutes(): int
    {
        return max(0, (int) $this->get('auto_confirm_minutes', 0));
    }

    public function getModeratorGroupID(): int
    {
        return max(0, (int) $this->get('moderator_group_id', 0));
    }

    /**
     * The key with only the last characters readable, for the settings form.
     */
    public function getMaskedApiKey(): string
    {
        $key = $this->getApiKey();
        if ($key === '') {
            return '';
        }

        return str_repeat('•', max(4, strlen($key) - 4)) . substr($key, -4);
    }
}
