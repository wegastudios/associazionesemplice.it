<?php

declare(strict_types=1);

use App\Modules\Core\Auth\ResetPasswordNotification;
use App\Modules\Core\Auth\TwoFactorEmailResetNotification;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Lang;


it('ogni regola di validazione usata nel codice ha la sua traduzione', function (): void {
    $known = ['accepted', 'accepted_if', 'active_url', 'after', 'after_or_equal', 'alpha', 'alpha_dash', 'alpha_num', 'array', 'before', 'before_or_equal', 'between', 'boolean', 'confirmed',
        'current_password', 'date', 'date_equals', 'date_format', 'decimal', 'declined', 'different', 'digits', 'digits_between', 'distinct', 'email', 'ends_with', 'enum', 'exists', 'extensions',
        'file', 'filled', 'gt', 'gte', 'image', 'in', 'integer', 'ip', 'ipv4', 'ipv6', 'json', 'list', 'lowercase', 'lt', 'lte', 'mac_address', 'max', 'max_digits', 'mimes', 'mimetypes', 'min',
        'min_digits', 'missing', 'multiple_of', 'not_in', 'not_regex', 'numeric', 'present', 'prohibited', 'regex', 'required', 'required_if', 'required_unless', 'required_with',
        'required_without', 'same', 'size', 'starts_with', 'string', 'timezone', 'unique', 'uppercase', 'url', 'uuid'];
    $sized = ['between', 'gt', 'gte', 'lt', 'lte', 'max', 'min', 'size'];

    $used = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        preg_match_all("/'([a-z_0-9|:,.\/-]{2,120})'/", $code, $m);
        foreach ($m[1] as $literal) {
            foreach (explode('|', $literal) as $part) {
                $name = explode(':', $part)[0];
                if (in_array($name, $known, true)) {
                    $used[$name] = true;
                }
            }
        }
    }

    $missing = [];
    foreach (array_keys($used) as $rule) {
        $text = Lang::get("validation.{$rule}");
        if ($text === "validation.{$rule}") {
            $missing[] = $rule;

            continue;
        }
        if (in_array($rule, $sized, true) && is_array($text)) {
            foreach (['numeric', 'string', 'file', 'array'] as $type) {
                if (isset($text[$type]) === false) {
                    $missing[] = "{$rule}.{$type}";
                }
            }
        }
    }

    expect($missing)->toBe([])
        ->and(array_keys($used))->toContain('extensions', 'required_unless');
});

it('le email di accesso non contengono parole inglesi', function (): void {
    $user = new User(['name' => 'Anna Rossi', 'email' => 'anna@example.com']);
    $english = ['Hello', 'Regards', 'Whoops', 'trouble clicking', 'rights reserved', 'Reset Password', 'Verify Email', 'password reset', 'no further action'];
    $mails = [
        (new ResetPasswordNotification('token'))->toMail($user),
        (new TwoFactorEmailResetNotification('Anna', 'https://example.com/x'))->toMail($user),
    ];

    foreach ($mails as $mail) {
        $html = (string) $mail->render();
        foreach ($english as $word) {
            expect($html)->not->toContain($word);
        }
    }
});
