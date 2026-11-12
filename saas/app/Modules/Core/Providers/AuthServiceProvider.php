<?php

declare(strict_types=1);

namespace App\Modules\Core\Providers;

use App\Modules\Core\Access\RequestOperator;
use App\Modules\Core\Auth\Actions\EnableTwoFactorOnce;
use App\Modules\Core\Auth\Actions\KeepTwoFactorForOperators;
use App\Modules\Core\Auth\Actions\ResetUserPassword;
use App\Modules\Core\Auth\Actions\UpdateUserPassword;
use App\Modules\Core\Auth\PasswordRules;
use App\Modules\Core\Auth\SamePasswordResetLinkResponse;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\CurrentOperator;
use App\Modules\Core\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

/** Accesso: Fortify con pagine Inertia, limiti ai tentativi, permessi degli operatori. */
final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentOperator::class, RequestOperator::class);
        $this->app->bind(EnableTwoFactorAuthentication::class, EnableTwoFactorOnce::class);
        $this->app->bind(DisableTwoFactorAuthentication::class, KeepTwoFactorForOperators::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, SamePasswordResetLinkResponse::class);
    }

    public function boot(): void
    {
        PasswordRules::register($this->app->isProduction());

        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        // Con l'accesso unico degli operatori (passo 6) l'accesso è /accedi del portale, non questa pagina.
        Fortify::loginView(static function () {
            if (config('saas.unified_login.operators') === true) {
                $public = config('saas.public_url');

                return redirect()->away(rtrim(is_string($public) && $public !== '' ? $public : 'https://associazionesemplice.it', '/').'/accedi');
            }

            return Inertia::render('Auth/Login', ['status' => session('status')]);
        });
        Fortify::twoFactorChallengeView(static fn () => Inertia::render('Auth/TwoFactorChallenge'));
        Fortify::requestPasswordResetLinkView(static fn () => Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]));
        Fortify::resetPasswordView(static fn (Request $request) => Inertia::render('Auth/ResetPassword', [
            'token' => $request->route('token'),
            'email' => $request->string('email')->toString(),
        ]));
        Fortify::confirmPasswordView(static fn () => Inertia::render('Auth/ConfirmPassword'));

        // Tentativi di accesso: 5 al minuto per email e indirizzo, 20 al minuto per indirizzo.
        RateLimiter::for('login', static function (Request $request): array {
            $email = Str::lower($request->string(Fortify::username())->toString());

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip())->response(self::lockout(...)),
                Limit::perMinute(20)->by('ip|'.$request->ip())->response(self::lockout(...)),
            ];
        });
        RateLimiter::for('two-factor', static function (Request $request): Limit {
            $id = $request->session()->get('login.id');

            return Limit::perMinute(5)->by('2fa|'.(is_string($id) ? $id : $request->ip()))->response(self::lockout(...));
        });

        // Permessi degli operatori nell'associazione corrente: Gate::allows('people.edit').
        foreach (Ability::cases() as $ability) {
            Gate::define($ability->value, static function (User $user) use ($ability): bool {
                $operator = app(CurrentOperator::class)->get();

                return $operator !== null && $operator->userId === $user->id && $operator->can($ability);
            });
        }

        $this->loadRoutesFrom(dirname(__DIR__).'/Http/routes.php');
    }

    /**
     * Troppi tentativi: 429 e un evento Lockout, che finisce negli eventi di sicurezza.
     *
     * @param  array<string, string>  $headers
     */
    private static function lockout(Request $request, array $headers): Response
    {
        event(new Lockout($request));

        return response('Troppi tentativi. Riprova tra un minuto.', 429, $headers);
    }
}
