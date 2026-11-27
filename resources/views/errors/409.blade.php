@php($showDashboardLink = auth()->hasUser() && ! request()->routeIs('dashboard'))

<x-error-page
    code="409"
    title="Ação não disponível"
    message="O estado atual deste registro não permite concluir a operação. Atualize a página antes de tentar novamente."
    :action-route="$showDashboardLink ? 'dashboard' : 'home'"
    :action-label="$showDashboardLink ? 'Voltar ao painel' : 'Ir ao início'"
/>
