document.addEventListener('livewire:init', () => {
    Livewire.hook('request', ({fail}) => {
        fail(({status, content, preventDefault}) => {
            try {
                content = JSON.parse(content)

                if (status === 419 || content.status === 419) {
                    window.location.reload()
                    preventDefault()
                }

                if (content.redirectTo) {
                    window.location = content.redirectTo
                    preventDefault()
                }

                if (content.toast) {
                    toast({toast: content.toast});
                    preventDefault()
                }
            } catch (e) {
                console.log(e)
            }
        })
    })
})
