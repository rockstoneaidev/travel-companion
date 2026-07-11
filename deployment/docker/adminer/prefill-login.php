<?php

/**
 * Pre-fills the Adminer login form from the ADMINER_DEFAULT_* environment
 * variables set in docker-compose.yml (the official image only implements
 * ADMINER_DEFAULT_SERVER itself). Local-dev convenience only — this file is
 * mounted into the adminer container's plugins-enabled/ directory and never
 * ships anywhere near staging.
 */
final class PrefillLoginPlugin extends \Adminer\Plugin
{
    public function loginFormField($name, $heading, $value)
    {
        if ($name === 'driver' && ! empty($_ENV['ADMINER_DEFAULT_DRIVER'])) {
            $driver = htmlspecialchars($_ENV['ADMINER_DEFAULT_DRIVER'], ENT_QUOTES);
            $selected = str_replace(' selected', '', $value);
            $selected = str_replace('value="'.$driver.'"', 'value="'.$driver.'" selected', $selected);

            return $heading.$selected."\n";
        }

        $prefill = [
            'username' => $_ENV['ADMINER_DEFAULT_USERNAME'] ?? '',
            'password' => $_ENV['ADMINER_DEFAULT_PASSWORD'] ?? '',
            'db' => $_ENV['ADMINER_DEFAULT_DB'] ?? '',
        ];

        if (empty($prefill[$name])) {
            return null; // fall through to the next plugin / Adminer's default
        }

        $filled = htmlspecialchars($prefill[$name], ENT_QUOTES);

        // The password input carries no value attribute; the others have value="".
        $value = $name === 'password'
            ? str_replace('<input ', '<input value="'.$filled.'" ', $value)
            : preg_replace('/value=""/', 'value="'.$filled.'"', $value, 1);

        return $heading.$value."\n";
    }
}

return new PrefillLoginPlugin;
