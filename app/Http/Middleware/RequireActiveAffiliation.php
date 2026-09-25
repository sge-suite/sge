<?php

namespace App\Http\Middleware;

use App\Support\ActiveAffiliationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireActiveAffiliation
{
    public function __construct(private ActiveAffiliationContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $affiliation = $this->context->resolve($user, $request->session());

        if ($affiliation === null) {
            if ($this->context->availableFor($user)->isEmpty()) {
                abort(403, 'Nenhum vínculo ativo está disponível para esta conta.');
            }

            return redirect()->route('affiliations.select');
        }

        return $next($request);
    }
}
