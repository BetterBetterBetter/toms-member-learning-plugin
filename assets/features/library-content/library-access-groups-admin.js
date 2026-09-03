document.addEventListener("DOMContentLoaded", () => {
  const page = document.querySelector(".tsol-library-access-groups-page")
  if (!page) return

  const list = page.querySelector("[data-access-groups-list]")
  const template = page.querySelector("[data-access-group-template]")
  const add = page.querySelector("[data-add-access-group]")
  let nextIndex = page.querySelectorAll("[data-access-group-card]").length

  const connect = card => {
    const name = card.querySelector("[data-access-group-name]")
    const summary = card.querySelector("[data-access-group-summary]")
    const remove = card.querySelector("[data-remove-access-group]")
    const removeValue = card.querySelector("[data-access-group-remove-value]")

    name?.addEventListener("input", () => {
      if (summary) summary.textContent = name.value.trim() || "New group"
    })
    remove?.addEventListener("click", () => {
      if (!window.confirm("Remove this group from the draft? Memberships that include it lose it when you save. Nothing changes for members until you publish.")) return
      if (removeValue instanceof HTMLInputElement) removeValue.value = "1"
      card.hidden = true
      card.querySelectorAll("input, textarea").forEach(field => {
        if (field !== removeValue) field.disabled = true
      })
    })
  }

  // Publish and undo use a plain confirmation dialog instead of a typed phrase.
  page.querySelectorAll("form[data-access-confirm]").forEach(form => {
    form.addEventListener("submit", event => {
      const message = form.getAttribute("data-access-confirm") || ""
      if (message && !window.confirm(message)) event.preventDefault()
    })
  })

  page.querySelectorAll("[data-access-group-card]").forEach(connect)
  add?.addEventListener("click", () => {
    if (!(template instanceof HTMLTemplateElement) || !list) return
    const wrapper = document.createElement("div")
    wrapper.innerHTML = template.innerHTML.replaceAll("__INDEX__", String(nextIndex++))
    const card = wrapper.firstElementChild
    if (!card) return
    list.append(card)
    connect(card)
    card.querySelector("[data-access-group-name]")?.focus()
  })
})
