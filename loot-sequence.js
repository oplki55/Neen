(() => {
  const status = document.querySelector("[data-sequence-status]");
  const roundNode = document.querySelector("[data-sequence-round]");
  const rewardNode = document.querySelector("[data-sequence-reward]");
  const preview = document.querySelector("[data-sequence-preview]");
  const patternNode = document.querySelector("[data-sequence-pattern]");
  const stickerTray = document.querySelector("[data-sequence-stickers]");
  const readyButton = document.querySelector("[data-sequence-ready]");
  const submitButton = document.querySelector("[data-sequence-submit]");
  const newButton = document.querySelector("[data-sequence-new]");
  if ([status, roundNode, rewardNode, preview, patternNode, stickerTray, readyButton, submitButton, newButton]
    .some((node) => !(node instanceof HTMLElement))) return;

  let csrfToken = "";
  let stickers = [];
  let session = null;
  let pattern = [];
  let recalling = false;
  let requestPending = false;

  const api = async (path, method = "GET", data = null) => {
    const headers = { Accept: "application/json" };
    const options = { method, credentials: "same-origin", cache: "no-store", headers };
    if (data !== null) {
      if (!csrfToken) csrfToken = (await api("/api/auth/csrf")).csrf_token;
      headers["Content-Type"] = "application/json";
      headers["X-CSRF-Token"] = csrfToken;
      options.body = JSON.stringify(data);
    }
    const response = await fetch(path, options);
    let result;
    try { result = await response.json(); } catch { throw new Error("The game service returned an unreadable response."); }
    if (!response.ok || result.success !== true) {
      const error = new Error(result.errors?.[0]?.message || "The game request could not be completed.");
      error.code = result.errors?.[0]?.code;
      throw error;
    }
    return result.data;
  };

  const setStatus = (text, error = false) => {
    status.textContent = text;
    status.classList.toggle("is-error", error);
  };

  const sticker = (id) => stickers.find((item) => item.id === id);

  const renderRound = (nextSession) => {
    session = nextSession;
    pattern = [];
    recalling = false;
    preview.hidden = false;
    roundNode.textContent = session ? `${session.round_number} / ${session.rounds_total}` : "—";
    preview.replaceChildren();
    stickerTray.replaceChildren();
    patternNode.textContent = session ? "Study the sequence, then start recall." : "Your pattern will appear here.";
    if (session?.sequence) {
      session.sequence.forEach((id, index) => {
        const item = document.createElement("li");
        const image = document.createElement("img");
        image.src = sticker(id)?.src || "";
        image.alt = `Step ${index + 1}`;
        item.append(image);
        preview.append(item);
      });
    }
    const playable = session && ["started", "in_progress"].includes(session.status);
    readyButton.hidden = !playable;
    submitButton.hidden = !playable;
    newButton.hidden = Boolean(playable);
    submitButton.disabled = true;
    readyButton.disabled = requestPending;
    for (const item of stickers) {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "sequence-sticker";
      button.disabled = !recalling || requestPending || pattern.length >= (session?.sequence_length ?? 0);
      button.setAttribute("aria-label", item.id.replaceAll("-", " "));
      const image = document.createElement("img");
      image.src = item.src;
      image.alt = "";
      button.append(image);
      button.addEventListener("click", () => {
        pattern.push(item.id);
        patternNode.textContent = pattern.map((id) => sticker(id)?.id.replaceAll("-", " ") || id).join(" · ");
        submitButton.disabled = pattern.length !== session.sequence_length || requestPending;
        renderStickerButtons();
      });
      stickerTray.append(button);
    }
    if (session?.status === "completed") setStatus("Sequence mastered! Your server-verified Earned Coins reward has been added.");
    else if (session?.status === "failed") setStatus("That pattern was not correct. Start another sequence when you’re ready.");
    else if (session) setStatus("Watch the displayed pattern, then repeat each sticker in order.");
  };

  const renderStickerButtons = () => {
    stickerTray.querySelectorAll("button").forEach((button) => {
      button.disabled = !recalling || requestPending || pattern.length >= (session?.sequence_length ?? 0);
    });
  };

  const load = async () => {
    try {
      const catalog = await api("/api/games/loot-sequence");
      stickers = catalog.game.stickers;
      rewardNode.textContent = new Intl.NumberFormat().format(catalog.game.reward_amount_coins);
      try {
        const current = await api("/api/games/loot-sequence/sessions/current");
        renderRound(current.session);
        if (current.session) roundNode.title = `Daily starts remaining: ${current.attempts_remaining_today}`;
      } catch (error) {
        if (error.code === "AUTH_REQUIRED") {
          renderRound(null);
          readyButton.hidden = true;
          submitButton.hidden = true;
          newButton.hidden = true;
          setStatus("Sign in to play and earn rewards. Your daily attempts are saved to your account.", true);
        } else throw error;
      }
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "The game could not be loaded.", true);
    }
  };

  readyButton.addEventListener("click", () => {
    if (!session || requestPending) return;
    recalling = true;
    preview.hidden = true;
    readyButton.hidden = true;
    pattern = [];
    patternNode.textContent = `Repeat all ${session.sequence_length} stickers in order.`;
    renderStickerButtons();
  });

  newButton.addEventListener("click", async () => {
    if (requestPending) return;
    requestPending = true;
    newButton.disabled = true;
    try {
      const result = await api("/api/games/loot-sequence/sessions", "POST", {
        idempotency_key: crypto.randomUUID(),
      });
      renderRound(result.session);
      setStatus("Watch the displayed pattern, then repeat each sticker in order.");
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "A new sequence could not be started.", true);
    } finally {
      requestPending = false;
      newButton.disabled = false;
      readyButton.disabled = false;
    }
  });

  submitButton.addEventListener("click", async () => {
    if (requestPending || !session || pattern.length !== session.sequence_length) return;
    requestPending = true;
    submitButton.disabled = true;
    try {
      const result = await api(`/api/games/loot-sequence/sessions/${session.id}/submit`, "POST", { pattern });
      renderRound(result.session);
      if (result.reward) {
        preview.hidden = false;
        setStatus(`Sequence mastered! +${new Intl.NumberFormat().format(result.reward.amount)} Earned Coins added to your wallet.`);
      } else if (result.correct) {
        preview.hidden = false;
        setStatus("Correct! The next round adds another step.");
      } else {
        preview.hidden = false;
        setStatus("That pattern was not correct. Start another sequence when you’re ready.");
      }
    } catch (error) {
      setStatus(error instanceof Error ? error.message : "The pattern could not be checked.", true);
    } finally {
      requestPending = false;
      readyButton.disabled = false;
      renderStickerButtons();
    }
  });

  load();
})();
