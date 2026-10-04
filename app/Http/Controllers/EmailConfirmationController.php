<?php

namespace App\Http\Controllers;

use App\Mail\ConfirmEmailMail;
use App\Models\User;
use App\Support\Verification\EmailConfirmation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Confirmar mi correo" (link firmado de los correos): el correo opcional de una cuenta con celular queda
 * verificado y empiezan a llegarle las copias de los avisos. Con el link vencido, manda uno nuevo.
 */
class EmailConfirmationController extends Controller
{
    public function __invoke(Request $request, int $user, string $hash): Response
    {
        $account = User::query()->find($user);
        $valid = $account !== null && filled($account->email) && URL::hasCorrectSignature($request)
            && hash_equals(EmailConfirmation::hash($account->email), $hash);

        if (! $valid) {
            return self::page('piensa', 'Este link no es válido', 'Puede que el correo de la cuenta haya cambiado. Si necesitás ayuda, escribinos a '.config('tuku.email').'.', Response::HTTP_FORBIDDEN);
        }

        if ($account->email_verified_at !== null) {
            return self::confirmed($account);
        }

        if (! URL::signatureHasNotExpired($request)) {
            // Un link nuevo, hasta 3 por día.
            if (RateLimiter::attempt("email-confirm:{$account->id}", 3, fn () => Mail::to($account->email)->queue(new ConfirmEmailMail($account)), 86400)) {
                return self::page('piensa', 'Este link venció', "Te mandamos uno nuevo a {$account->email}.", Response::HTTP_GONE);
            }

            return self::page('piensa', 'Este link venció', 'Buscá el último correo que te mandamos para confirmar tu correo.', Response::HTTP_GONE);
        }

        $account->forceFill(['email_verified_at' => now()])->save();

        return self::confirmed($account);
    }

    private static function confirmed(User $account): Response
    {
        return self::page('salta', 'Listo, confirmaste tu correo', "Desde ahora te mandamos a {$account->email} una copia de los avisos y de los códigos que te lleguen por WhatsApp.");
    }

    private static function page(string $pose, string $title, string $message, int $status = Response::HTTP_OK): Response
    {
        return response()->view('site.aviso', ['pose' => $pose, 'title' => $title, 'message' => $message], $status);
    }
}
