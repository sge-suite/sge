<?php

namespace App\Http\Middleware;

use App\Support\ActiveAffiliationContext;
use Closure;
use Illuminate\Http\Request;
use Spatie\Activitylog\Support\CauserResolver;
use Symfony\Component\HttpFoundation\Response;

class SetAuditActor
{
    public function __construct(
        private ActiveAffiliationContext $context,
        private CauserResolver $causerResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $affiliation = $user === null ? null : $this->context->resolve($user, $request->session());

        if ($user !== null && $affiliation === null && ! $request->isMethodSafe()
            && ! $request->routeIs('affiliations.store', 'logout')) {
            if ($this->context->availableFor($user)->isEmpty()) {
                abort(403, 'Nenhum vínculo ativo está disponível para esta conta.');
            }

            return redirect()->route('affiliations.select');
        }

        return $this->causerResolver->withCauser($affiliation, fn (): Response => $next($request));
    }
}
