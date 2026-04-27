<div
    x-data="{
        init() {
            if (!window.Echo) return;

            window.Echo.private('crm-alerts')
                .listen('.deposit.received', (e) => {
                    $dispatch('echo-deposit-received', e);
                })
                .listen('.lead.converted', (e) => {
                    $dispatch('echo-lead-converted', e);
                })
                .listen('.withdrawal.large', (e) => {
                    $dispatch('echo-withdrawal-large', e);
                });
        }
    }"
    @echo-deposit-received.window="$wire.onDepositReceived($event.detail)"
    @echo-lead-converted.window="$wire.onLeadConverted($event.detail)"
    @echo-withdrawal-large.window="$wire.onWithdrawalLarge($event.detail)"
></div>
