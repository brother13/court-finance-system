import type { App, DirectiveBinding } from 'vue'
import { useContextStore } from '../stores/context'

const applyPermission = (el: HTMLElement, binding: DirectiveBinding<string | string[]>) => {
  const store = useContextStore()
  const codes = Array.isArray(binding.value) ? binding.value : [binding.value]
  if (!codes.some((code) => store.hasPermission(code))) {
    el.parentNode?.removeChild(el)
  }
}

export const permissionDirective = {
  mounted: applyPermission
}

export const installPermissionDirective = (app: App) => {
  app.directive('permission', permissionDirective)
}
