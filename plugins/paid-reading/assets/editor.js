(() => {
  "use strict";

  const dialog = document.querySelector("#pr-editor-dialog");
  const button = document.querySelector("[data-pr-editor-open]");
  const enabledValue = document.querySelector("#pr-editor-enabled-value");
  const priceValue = document.querySelector("#pr-editor-price-value");
  if (!(dialog instanceof HTMLDialogElement) || !(button instanceof HTMLButtonElement)
    || !(enabledValue instanceof HTMLInputElement) || !(priceValue instanceof HTMLInputElement)) return;
  const applyButton = dialog.querySelector("[data-pr-editor-apply]");
  const enabled = dialog.querySelector("#pr-editor-enabled");
  const price = dialog.querySelector("#pr-editor-price");
  const errorBox = dialog.querySelector("[data-pr-editor-error]");
  if (!(applyButton instanceof HTMLButtonElement) || !(enabled instanceof HTMLInputElement)
    || !(price instanceof HTMLInputElement) || !(errorBox instanceof HTMLElement)) return;

  const clearError = () => {
    errorBox.textContent = "";
    errorBox.hidden = true;
    price.removeAttribute("aria-invalid");
  };
  const syncPrice = () => { price.disabled = !enabled.checked; };
  const syncButton = () => { button.setAttribute("aria-pressed", enabledValue.value === "1" ? "true" : "false"); };
  syncButton();

  button.addEventListener("click", () => {
    if (typeof dialog.showModal !== "function") {
      window.alert("当前浏览器不支持设置窗口，请使用新版浏览器。");
      return;
    }
    enabled.checked = enabledValue.value === "1";
    price.value = priceValue.value;
    clearError();
    syncPrice();
    dialog.showModal();
    enabled.focus();
  });
  enabled.addEventListener("change", () => { clearError(); syncPrice(); });
  price.addEventListener("input", clearError);
  dialog.querySelectorAll("[data-pr-editor-close]").forEach((close) => {
    close.addEventListener("click", () => dialog.close());
  });
  dialog.addEventListener("close", () => button.focus());

  const applySettings = () => {
    clearError();
    let nextPrice = priceValue.value;
    if (enabled.checked) {
      const match = price.value.trim().match(/^(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,2}))?$/);
      const cents = match ? Number(match[1]) * 100 + Number((match[2] || "").padEnd(2, "0")) : 0;
      if (!match || cents < 1 || cents > 100000000) {
        errorBox.textContent = "阅读价格必须为 0.01 至 1000000.00 元，最多两位小数。";
        errorBox.hidden = false;
        price.setAttribute("aria-invalid", "true");
        price.focus();
        return;
      }
      nextPrice = `${Math.floor(cents / 100)}.${String(cents % 100).padStart(2, "0")}`;
    }
    enabledValue.value = enabled.checked ? "1" : "0";
    priceValue.value = nextPrice;
    syncButton();
    enabledValue.dispatchEvent(new Event("input", { bubbles: true }));
    priceValue.dispatchEvent(new Event("input", { bubbles: true }));
    dialog.close();
  };
  applyButton.addEventListener("click", applySettings);
  price.addEventListener("keydown", (event) => {
    if (event.key === "Enter") {
      event.preventDefault();
      applySettings();
    }
  });
})();
