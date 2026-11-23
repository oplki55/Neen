(() => {
  const status = document.querySelector("[data-leaderboard-status]");
  const panels = [...document.querySelectorAll("[data-leaderboard]")];
  if (!(status instanceof HTMLElement) || panels.length === 0) return;

  const labels = {
    earned_coins: (score) => `${new Intl.NumberFormat().format(score)} Earned Coins`,
    game_wins: (score) => `${new Intl.NumberFormat().format(score)} wins`,
    attendance_streaks: (score) => `${new Intl.NumberFormat().format(score)} day${score === 1 ? "" : "s"}`,
  };

  const render = (panel, rows) => {
    const list = panel.querySelector("ol");
    if (!(list instanceof HTMLOListElement)) return;
    list.replaceChildren();
    if (!Array.isArray(rows) || rows.length === 0) {
      const empty = document.createElement("li");
      empty.className = "leaderboard-empty";
      empty.textContent = "No rankings yet.";
      list.append(empty);
      return;
    }

    rows.forEach((row) => {
      const item = document.createElement("li");
      item.className = "leaderboard-row";
      const rank = document.createElement("strong");
      rank.className = "leaderboard-rank";
      rank.textContent = String(row.rank);
      const identity = document.createElement("span");
      identity.className = "leaderboard-identity";
      const displayName = document.createElement("strong");
      displayName.textContent = row.display_name || row.username;
      const username = document.createElement("small");
      username.textContent = `@${row.username}`;
      identity.append(displayName, username);
      const score = document.createElement("span");
      score.className = "leaderboard-score";
      score.textContent = labels[panel.dataset.leaderboard](row.score);
      item.append(rank, identity, score);
      list.append(item);
    });
  };

  fetch("/api/events/leaderboard", {
    headers: { Accept: "application/json" },
    credentials: "same-origin",
    cache: "no-store",
  })
    .then(async (response) => {
      let result;
      try {
        result = await response.json();
      } catch {
        throw new Error("The leaderboard service returned an unreadable response.");
      }
      if (!response.ok || result.success !== true || !result.data?.leaderboards) {
        throw new Error(result.errors?.[0]?.message || "The leaderboard could not be loaded.");
      }
      panels.forEach((panel) => render(panel, result.data.leaderboards[panel.dataset.leaderboard]));
      status.textContent = "Rankings loaded from server activity.";
    })
    .catch((error) => {
      panels.forEach((panel) => render(panel, []));
      status.textContent = error instanceof Error ? error.message : "The leaderboard could not be loaded.";
      status.classList.add("is-error");
    });
})();
