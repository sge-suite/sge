@php($showDashboardLink = auth()->hasUser() && ! request()->routeIs('dashboard'))

<x-error-page
    code="429"
    title="Muitas tentativas"
    message="Você realizou muitas solicitações em pouco tempo. Aguarde alguns instantes e tente novamente."
    :action-route="$showDashboardLink ? 'dashboard' : 'home'"
    :action-label="$showDashboardLink ? 'Voltar ao painel' : 'Ir ao início'"
/>
