(() => {
  const status = document.querySelector("[data-challenge-status]");
  const cards = [...document.querySelectorAll("[data-challenge]")];
  if (!(status instanceof HTMLElement) || cards.length === 0) return;

  const request = async (path) => {
    const response = await fetch(path, {
      headers: { Accept: "application/json" },
      credentials: "same-origin",
      cache: "no-store",
    });
    let result;
    try { result = await response.json(); } catch { throw new Error("Challenge data could not be read."); }
    if (!response.ok || result.success !== true) {
      const error = new Error(result.errors?.[0]?.message || "Challenge data could not be loaded.");
      error.code = result.errors?.[0]?.code;
      throw error;
    }
    return result.data;
  };

  const card = (slug) => cards.find((item) => item.dataset.challenge === slug);

  const loadGame = async (slug, endpoint, currentEndpoint, name) => {
    const node = card(slug);
    if (!node) return;
    const [catalogResult, currentResult] = await Promise.allSettled([
      request(endpoint),
      request(currentEndpoint),
    ]);
    const attempts = node.querySelector("[data-challenge-attempts]");
    const reward = node.querySelector("[data-challenge-reward]");
    const link = node.querySelector("a");
    if (!(attempts instanceof HTMLElement) || !(reward instanceof HTMLElement)) return;
    if (catalogResult.status === "rejected") {
      attempts.textContent = "Game data unavailable";
      reward.textContent = "—";
      status.textContent = `${name} settings could not be loaded.`;
      return;
    }
    const game = catalogResult.value.game;
    reward.textContent = `${new Intl.NumberFormat().format(game.reward_amount_coins)} Earned Coins`;
    if (game.status !== "active" && link instanceof HTMLAnchorElement) {
      link.setAttribute("aria-disabled", "true");
      link.textContent = "Temporarily unavailable";
      link.removeAttribute("href");
      attempts.textContent = "Not currently active";
      return;
    }
    if (currentResult.status === "fulfilled") {
      attempts.textContent = `${currentResult.value.attempts_remaining_today} of ${game.free_attempts_per_day} starts left today`;
    } else if (currentResult.reason?.code === "AUTH_REQUIRED") {
      attempts.textContent = `Sign in · ${game.free_attempts_per_day} starts per day`;
    } else {
      attempts.textContent = "Daily starts unavailable";
    }
  };

  const loadSunnyIsland = async () => {
    const node = card("sunny_island");
    if (!node) return;
    const attempts = node.querySelector("[data-challenge-attempts]");
    const reward = node.querySelector("[data-challenge-reward]");
    const level = node.querySelector("[data-challenge-level]");
    const link = node.querySelector("a");
    if (!(attempts instanceof HTMLElement) || !(reward instanceof HTMLElement)) return;
    try {
      const { state: island } = await request("/api/games/sunny-island/state");
      const adventure = island.adventure;
      reward.textContent = `${new Intl.NumberFormat().format(adventure.reward_amount_coins)} Earned Coins`;
      if (level instanceof HTMLElement) {
        level.textContent = `Island level: ${new Intl.NumberFormat().format(island.progress.level)}`;
      }
      if (adventure.status !== "active" && link instanceof HTMLAnchorElement) {
        link.setAttribute("aria-disabled", "true");
        link.textContent = "Temporarily unavailable";
        link.removeAttribute("href");
        attempts.textContent = "Adventure not currently active";
        return;
      }
      attempts.textContent = `${adventure.attempts_remaining_today} of ${adventure.free_attempts_per_day} daily adventures left`;
    } catch (error) {
      if (error.code === "AUTH_REQUIRED") {
        attempts.textContent = "Sign in to view daily adventures";
        reward.textContent = "Sign in to see reward";
        if (level instanceof HTMLElement) level.textContent = "Sign in to see island level";
        return;
      }
      attempts.textContent = "Adventure status unavailable";
      reward.textContent = "—";
      if (level instanceof HTMLElement) level.textContent = "Island level unavailable";
      status.textContent = error instanceof Error ? error.message : "Sunny Island data could not be loaded.";
    }
  };

  const load = async () => {
    const user = await request("/api/auth/me").catch(() => null);
    await Promise.all([
      loadGame("memory-match", "/api/games/memory-match", "/api/games/memory-match/sessions/current", "Memory Match"),
      loadGame("picture-jigsaw", "/api/games/picture-jigsaw", "/api/games/picture-jigsaw/sessions/current", "Picture Jigsaw"),
      loadGame("target-rush", "/api/games/target-rush", "/api/games/target-rush/sessions/current", "Target Rush"),
      loadGame("loot_sequence", "/api/games/loot-sequence", "/api/games/loot-sequence/sessions/current", "Loot Sequence"),
      loadSunnyIsland(),
    ]);

    const attendance = card("attendance");
    if (attendance) {
      const attemptNode = attendance.querySelector("[data-challenge-attempts]");
      if (user) {
        try {
          const result = await request("/api/attendance/daily");
          attemptNode.textContent = result.attendance.checked_in_today
            ? `Checked in today · cycle ${result.attendance.cycle_progress}/7`
            : `Check in today · cycle day ${result.attendance.next_cycle_day}/7`;
        } catch {
          attemptNode.textContent = "Attendance status unavailable";
        }
      } else {
        attemptNode.textContent = "Sign in to check in";
      }
    }
    status.textContent = "Daily challenge settings are loaded from the server.";
  };

  load().catch((error) => {
    status.textContent = error instanceof Error ? error.message : "Challenges could not be loaded.";
    status.classList.add("is-error");
  });
})();
