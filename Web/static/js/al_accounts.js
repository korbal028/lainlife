async function openAccountsSwitcher() {
    let data
    try {
        data = await (await fetch('/accounts.json')).json()
    } catch(e) {
        return fastError(tr('error'))
    }

    const hash = encodeURIComponent(u("meta[name=csrf]").attr("value"))
    const style = Number(window.openvk?.avatar_style ?? 0)
    const rows = data.accounts.map(account => `
        <div class="accounts-switcher--item${account.current ? ' accounts-switcher--item_current' : ''}" data-id="${Number(account.id)}">
            <img class="avatar-style-${style}" src="${escapeHtml(account.avatar)}" alt="" />
            <span class="accounts-switcher--info">
                <b>${escapeHtml(account.name)}</b>
                <span>${account.current ? escapeHtml(tr('accounts_current')) : escapeHtml(account.contact)}</span>
            </span>
            ${account.current ? '' : `<a class="accounts-switcher--forget" title="${escapeHtml(tr('accounts_forget'))}">×</a>`}
        </div>`).join('')

    const canAdd = data.accounts.length < data.limit
    const msg = new CMessageBox({
        title: tr('accounts_title'),
        body: `
            <div class="accounts-switcher">
                ${rows}
                ${canAdd ? '' : `<div class="accounts-switcher--hint">${escapeHtml(tr('accounts_limit').replace('%N%', data.limit))}</div>`}
            </div>`,
        buttons: canAdd ? [tr('accounts_add'), tr('close')] : [tr('close')],
        callbacks: canAdd ? [() => location.assign('/login?add_account=1'), Function.noop] : [Function.noop],
    })

    msg.getNode().find('.accounts-switcher').on('click', async e => {
        const item = e.target.closest('[data-id]')
        if(!item) return

        if(e.target.closest('.accounts-switcher--forget')) {
            await fetch(`/accounts/forget${item.dataset.id}?hash=${hash}`)
            item.remove()
            return
        }

        if(!item.classList.contains('accounts-switcher--item_current')) {
            location.assign(`/accounts/switch${item.dataset.id}?hash=${hash}`)
        }
    })
}

u(document).on('click', '#switch_account_link', e => {
    e.preventDefault()
    openAccountsSwitcher()
})
