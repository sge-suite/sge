@php($showDashboardLink = auth()->hasUser() && ! request()->routeIs('dashboard'))

<x-error-page
    code="403"
    title="Acesso restrito"
    message="Seu vínculo atual não tem permissão para acessar esta página."
    :action-route="$showDashboardLink ? 'dashboard' : 'home'"
    :action-label="$showDashboardLink ? 'Voltar ao painel' : 'Ir ao início'"
/>
