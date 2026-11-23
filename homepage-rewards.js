(() => {
  const widget = document.querySelector("[data-home-reward]");
  if (!(widget instanceof HTMLElement)) return;

  const amount = widget.querySelector("[data-home-reward-amount]");
  const balance = widget.querySelector("[data-home-reward-balance]");
  const button = widget.querySelector("[data-home-reward-claim]");
  const message = widget.querySelector("[data-home-reward-message]");
  if (
    !(amount instanceof HTMLElement)
    || !(balance instanceof HTMLElement)
    || !(button instanceof HTMLButtonElement)
    || !(message instanceof HTMLElement)
  ) return;

  const request = async (path, options = {}) => {
    const response = await fetch(path, {
      ...options,
      credentials: "same-origin",
      cache: "no-store",
      headers: {
        Accept: "application/json",
        ...(options.body ? { "Content-Type": "application/json" } : {}),
        ...(options.headers ?? {}),
      },
    });
    let result;
    try {
      result = await response.json();
    } catch {
      throw new Error("The reward service returned an unreadable response.");
    }
    if (!response.ok || result.success !== true) {
      const error = result.errors?.[0];
      const failure = new Error(
        typeof error?.message === "string" ? error.message : `Reward request failed (${response.status}).`,
      );
      failure.code = typeof error?.code === "string" ? error.code : "";
      throw failure;
    }
    return result.data;
  };

  const loadState = async () => {
    const { reward } = await request("/api/homepage-rewards");
    amount.textContent = reward.configured ? String(reward.reward_amount) : "Not configured";
    balance.textContent = String(reward.wallet.coin_balance);
    if (!reward.configured) {
      button.disabled = true;
      button.textContent = "Reward unavailable";
      message.textContent = "A manager must configure and enable this reward.";
    } else if (reward.can_claim) {
      button.disabled = false;
      button.textContent = "Claim homepage reward";
      message.textContent = `Claim availability ends ${reward.next_window_at} (UTC).`;
    } else {
      button.disabled = true;
      button.textContent = "Already claimed";
      message.textContent = `You have claimed this reward window. Next window: ${reward.next_window_at}.`;
    }
  };

  const refresh = async () => {
    try {
      await loadState();
    } catch (error) {
      if (error?.code === "AUTH_REQUIRED") {
        button.disabled = true;
        button.textContent = "Sign in to claim";
        message.textContent = "Sign in to NEEN to check and claim a homepage reward.";
        return;
      }
      button.disabled = true;
      button.textContent = "Reward status unavailable";
      message.textContent = error instanceof Error ? error.message : "The reward service is unavailable.";
    }
  };

  const renderGuestState = () => {
    button.disabled = true;
    button.textContent = "Sign in to claim";
    message.textContent = "Sign in to NEEN to check and claim a homepage reward.";
  };

  const authStatus = window.neenAuthStatus;
  if (authStatus === "checking") {
    const handleAuthStatus = (event) => {
      window.removeEventListener("neen:authstatus", handleAuthStatus);
      if (event.detail?.status === "signed-out") {
        renderGuestState();
      } else {
        refresh();
      }
    };
    window.addEventListener("neen:authstatus", handleAuthStatus);
  } else if (authStatus === "signed-out") {
    renderGuestState();
  } else {
    refresh();
  }

  window.addEventListener("neen:authchange", refresh);

  button.addEventListener("click", async () => {
    button.disabled = true;
    message.textContent = "Submitting claim to the server…";
    try {
      const csrf = await request("/api/auth/csrf");
      if (typeof csrf.csrf_token !== "string") {
        throw new Error("The server did not provide a CSRF token. Please sign in again.");
      }
      const result = await request("/api/homepage-rewards", {
        method: "POST",
        headers: { "X-CSRF-Token": csrf.csrf_token },
        body: "{}",
      });
      balance.textContent = String(result.wallet.coin_balance);
      message.textContent = result.created
        ? `${result.amount} Earned Coins were credited to your wallet.`
        : "This reward was already claimed; your wallet was not changed.";
      await loadState();
    } catch (error) {
      message.textContent = error instanceof Error ? error.message : "The claim could not be completed.";
      await refresh();
    }
  });
})();
