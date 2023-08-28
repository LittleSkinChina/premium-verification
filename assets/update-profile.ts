document.querySelector('#update-profile')?.addEventListener('click', async () => {
  const { code, message }: { code: null; message: string } =
    await globalThis.blessing.fetch.post('/user/premium/update')
  const { toast } = globalThis.blessing.notify
  if (code === 0) {
    toast.success(message)
  } else {
    toast.error(message)
  }
})
