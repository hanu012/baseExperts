{{-- Handles the Copy / Share buttons on the temporary-password notification
     (UserResource::sendTemporaryPasswordNotification). --}}
<script>
    (() => {
        const confirm = (title) => new FilamentNotification().title(title).success().send()

        // navigator.clipboard only exists in a secure context; plain http on a
        // LAN address is not one, so fall back to a hidden textarea there.
        const fallbackCopy = (text) => {
            const area = document.createElement('textarea')
            area.value = text
            area.style.position = 'fixed'
            area.style.opacity = '0'
            document.body.appendChild(area)
            area.select()
            const copied = document.execCommand('copy')
            area.remove()

            return copied
        }

        const copy = (text, confirmation) => {
            const done = () => confirm(confirmation)
            const failed = () => new FilamentNotification().title('Could not copy — select the text and copy it by hand').danger().send()

            if (window.navigator.clipboard && window.isSecureContext) {
                window.navigator.clipboard.writeText(text).then(done, () => (fallbackCopy(text) ? done() : failed()))

                return
            }

            fallbackCopy(text) ? done() : failed()
        }

        const register = () => {
            Livewire.on('copy-temporary-credential', ({ text, confirmation }) => copy(text, confirmation))

            Livewire.on('share-temporary-credential', ({ text }) => {
                if (window.navigator.share) {
                    window.navigator.share({ text }).catch(() => {})

                    return
                }

                copy(text, 'Message copied — paste it anywhere')
            })
        }

        window.Livewire ? register() : document.addEventListener('livewire:init', register)
    })()
</script>
