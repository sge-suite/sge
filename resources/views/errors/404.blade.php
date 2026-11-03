@php($showDashboardLink = auth()->hasUser() && ! request()->routeIs('dashboard'))

<x-error-page
    code="404"
    title="Página não encontrada"
    message="O link pode estar incorreto ou a página pode ter sido removida."
    :action-route="$showDashboardLink ? 'dashboard' : 'home'"
    :action-label="$showDashboardLink ? 'Voltar ao painel' : 'Ir ao início'"
/>
