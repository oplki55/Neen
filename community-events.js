(() => {
  const path = window.location.pathname.split("/").pop();
  const emptyState = document.querySelector(".events-empty-state");
  const eventsContainer = document.querySelector("[data-events-list]");

  const make = (tag, className = "", text = "") => {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text) element.textContent = text;
    return element;
  };

  const apiRequest = async (endpoint, options = {}) => {
    const response = await fetch(endpoint, {
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
      throw new Error("The events service returned an unreadable response.");
    }
    if (!response.ok || result.success !== true) {
      const error = result.errors?.[0];
      const failure = new Error(
        typeof error?.message === "string" ? error.message : `Event request failed (${response.status}).`,
      );
      failure.code = typeof error?.code === "string" ? error.code : "";
      throw failure;
    }
    return result.data;
  };

  const withCsrf = async (endpoint, body) => {
    const csrf = await apiRequest("/api/auth/csrf");
    if (typeof csrf.csrf_token !== "string") throw new Error("Sign in again before continuing.");
    return apiRequest(endpoint, {
      method: "POST",
      headers: { "X-CSRF-Token": csrf.csrf_token },
      body: JSON.stringify(body),
    });
  };

  const showState = (container, text, isError = false) => {
    container.replaceChildren(make("p", isError ? "events-guard-note is-error" : "events-guard-note", text));
  };

  const renderResults = (event) => {
    const results = event.results;
    if (!Array.isArray(results)) return null;
    const list = make("ol", "events-result-list");
    for (const result of results) {
      const row = make("li");
      row.append(make("strong", "", result.label), make("span", "", `${result.vote_count} votes`));
      list.append(row);
    }
    return list;
  };

  const eventCard = (event, includeVote = false) => {
    const card = make("article", "events-empty-card");
    card.append(make("span", "events-card-index", event.status.replaceAll("_", " ")));
    card.append(make("h3", "", event.title));
    card.append(make("p", "", event.description));
    card.append(make("p", "events-date-line", `${new Date(`${event.starts_at.replace(" ", "T")}Z`).toLocaleString()} — ${new Date(`${event.ends_at.replace(" ", "T")}Z`).toLocaleString()}`));
    if (includeVote && event.status === "results_published") {
      const results = renderResults(event);
      if (results) card.append(results);
    } else if (includeVote && event.can_vote) {
      card.append(make("p", "events-guard-note", "Choose one option. Your account can vote once."));
      const options = make("div", "events-option-list");
      for (const option of event.options) {
        const button = make("button", "outline-link", option.label);
        button.type = "button";
        button.addEventListener("click", async () => {
          options.querySelectorAll("button").forEach((item) => { item.disabled = true; });
          try {
            const result = await withCsrf(`/api/events/${event.id}/votes`, { option_id: option.id });
            renderEventVotes([result.event], options.closest("[data-vote-card]")?.parentElement ?? options.parentElement);
          } catch (error) {
            showState(options, error instanceof Error ? error.message : "The vote could not be recorded.", true);
            if (error?.code === "AUTH_REQUIRED") {
              const link = make("a", "outline-link", "Sign in to vote");
              link.href = "account.html";
              options.append(link);
            }
          }
        });
        options.append(button);
      }
      card.append(options);
    } else if (includeVote && event.my_vote_option_id !== null) {
      const choice = event.options.find((option) => option.id === event.my_vote_option_id);
      card.append(make("p", "events-guard-note", `Your vote: ${choice?.label ?? "recorded"}. Results will be shown after review.`));
    } else if (includeVote && event.status !== "results_published") {
      card.append(make("p", "events-guard-note", "Voting is closed or paused. Results appear after an authorized review."));
    }
    const details = make("a", "outline-link", "View event details");
    details.href = `events-detail.html?id=${encodeURIComponent(event.id)}`;
    card.append(details);
    return card;
  };

  const renderEventVotes = (events, container) => {
    if (!Array.isArray(events) || events.length === 0) {
      showState(container, "There are no published community events.");
      return;
    }
    container.replaceChildren(...events.map((event) => eventCard(event, true)));
  };

  const loadPublicEvents = async () => {
    const { events } = await apiRequest("/api/events");
    if (eventsContainer instanceof HTMLElement) {
      if (!Array.isArray(events) || events.length === 0) {
        showState(eventsContainer, "No published community events are available.");
      } else {
        eventsContainer.replaceChildren(...events.map((event) => eventCard(event)));
      }
    }
    const serviceState = document.querySelector("[data-events-service-state]");
    const serviceMessage = document.querySelector("[data-events-service-message]");
    if (serviceState) serviceState.textContent = "LIVE";
    if (serviceMessage) {
      serviceMessage.textContent = `${events.length} published event${events.length === 1 ? "" : "s"} loaded from the server.`;
    }
    return events;
  };

  const renderEventDetail = (container, event) => {
    container.replaceChildren();
    container.append(make("span", "events-empty-symbol", "✦"));
    container.append(make("h2", "", event.title));
    container.append(make("p", "", event.description));
    container.append(make("p", "events-guard-note", `Status: ${event.status.replaceAll("_", " ")} · ${event.total_votes} recorded vote${event.total_votes === 1 ? "" : "s"}`));
    const list = make("ul", "events-option-list");
    for (const option of event.options) {
      const item = make("li", "", option.label);
      if (event.status === "results_published") item.append(make("strong", "", ` — ${option.vote_count} votes`));
      list.append(item);
    }
    container.append(list);
    const details = make("p", "events-guard-note", `Starts ${event.starts_at} UTC · ends ${event.ends_at} UTC.`);
    container.append(details);
    if (event.can_vote) {
      renderEventVotes([event], container);
      return;
    }
    if (event.results) {
      const results = renderResults(event);
      if (results) container.append(results);
    }
    const back = make("a", "button button-primary", "Back to Events");
    back.href = "events.html";
    container.append(back);
  };

  const loadDetail = async () => {
    if (!(emptyState instanceof HTMLElement)) return;
    const id = new URLSearchParams(window.location.search).get("id");
    if (!id || !/^[1-9][0-9]*$/.test(id)) {
      showState(emptyState, "Choose an event from the Events Hub to see its details.", true);
      return;
    }
    try {
      const { event } = await apiRequest(`/api/events/${encodeURIComponent(id)}`);
      renderEventDetail(emptyState, event);
    } catch (error) {
      showState(emptyState, error instanceof Error ? error.message : "Event details could not be loaded.", true);
    }
  };

  const loadSuggestions = async (container) => {
    const { suggestions } = await apiRequest("/api/events/suggestions");
    if (!Array.isArray(suggestions) || suggestions.length === 0) {
      showState(container, "You have not submitted any event suggestions yet.");
      return;
    }
    const list = make("div", "events-user-records");
    for (const suggestion of suggestions) {
      const card = make("article", "events-empty-card");
      card.append(make("span", "events-card-index", suggestion.status));
      card.append(make("h3", "", suggestion.title));
      card.append(make("p", "", suggestion.description));
      if (suggestion.admin_note) card.append(make("p", "events-guard-note", suggestion.admin_note));
      list.append(card);
    }
    container.replaceChildren(list);
  };

  const loadRewards = async (container) => {
    const { rewards } = await apiRequest("/api/events/rewards");
    if (!Array.isArray(rewards) || rewards.length === 0) {
      showState(container, "No event rewards have been issued to this account.");
      return;
    }
    const list = make("div", "events-user-records");
    for (const reward of rewards) {
      const card = make("article", "events-empty-card");
      card.append(make("span", "events-card-index", `${reward.reward_amount} Earned Coins`));
      card.append(make("h3", "", reward.event_title));
      card.append(make("p", "", reward.reason));
      card.append(make("p", "events-date-line", reward.created_at));
      list.append(card);
    }
    container.replaceChildren(list);
  };

  if (path === "events.html" || path === "") {
    loadPublicEvents().catch((error) => {
      if (eventsContainer instanceof HTMLElement) showState(eventsContainer, error.message, true);
      const serviceState = document.querySelector("[data-events-service-state]");
      const serviceMessage = document.querySelector("[data-events-service-message]");
      if (serviceState) serviceState.textContent = "UNAVAILABLE";
      if (serviceMessage) serviceMessage.textContent = error.message;
    });
  }

  if (path === "events-votes.html" && emptyState instanceof HTMLElement) {
    loadPublicEvents()
      .then((events) => renderEventVotes(events, emptyState))
      .catch((error) => showState(emptyState, error.message, true));
  }

  if (path === "events-detail.html") loadDetail();

  if (path === "events-suggestions.html" && emptyState instanceof HTMLElement) {
    emptyState.replaceChildren();
    emptyState.append(make("h2", "", "Share an event idea"));
    const form = make("form", "events-suggestion-form");
    const titleLabel = make("label", "", "Suggestion title");
    const title = make("input");
    title.name = "title";
    title.maxLength = 150;
    title.required = true;
    titleLabel.append(title);
    const descriptionLabel = make("label", "", "Description");
    const description = make("textarea");
    description.name = "description";
    description.maxLength = 4000;
    description.required = true;
    description.rows = 5;
    descriptionLabel.append(description);
    const submit = make("button", "button button-primary", "Submit suggestion");
    submit.type = "submit";
    const status = make("p", "events-guard-note");
    status.setAttribute("role", "status");
    const mine = make("div", "events-user-records");
    form.append(titleLabel, descriptionLabel, submit, status);
    emptyState.append(form, make("h2", "", "Your suggestions"), mine);
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      submit.disabled = true;
      try {
        await withCsrf("/api/events/suggestions", { title: title.value, description: description.value });
        form.reset();
        status.textContent = "Suggestion submitted for review.";
        await loadSuggestions(mine);
      } catch (error) {
        status.textContent = error instanceof Error ? error.message : "Suggestion could not be submitted.";
      } finally {
        submit.disabled = false;
      }
    });
    loadSuggestions(mine).catch((error) => {
      showState(mine, error instanceof Error ? error.message : "Your suggestions could not be loaded.", true);
      if (error?.code === "AUTH_REQUIRED") {
        const link = make("a", "outline-link", "Sign in to submit and follow suggestions");
        link.href = "account.html";
        mine.append(link);
      }
    });
  }

  if ((path === "events-results.html" || path === "events-rewards.html") && emptyState instanceof HTMLElement) {
    if (path === "events-results.html") {
      loadPublicEvents().then((events) => {
        const published = events.filter((event) => event.status === "results_published");
        if (published.length === 0) {
          showState(emptyState, "No event results have been approved and published.");
          return;
        }
        emptyState.replaceChildren(...published.map((event) => eventCard(event, true)));
      }).catch((error) => showState(emptyState, error.message, true));
    } else {
      loadRewards(emptyState).catch((error) => {
        showState(emptyState, error instanceof Error ? error.message : "Event rewards could not be loaded.", true);
        if (error?.code === "AUTH_REQUIRED") {
          const link = make("a", "outline-link", "Sign in to view your event rewards");
          link.href = "account.html";
          emptyState.append(link);
        }
      });
    }
  }

  if (path === "events-profile.html" && emptyState instanceof HTMLElement) {
    emptyState.replaceChildren();
    emptyState.append(make("h2", "", "Your event activity"));
    const suggestions = make("section", "events-user-panel");
    suggestions.append(make("h3", "", "Suggestions"));
    const rewards = make("section", "events-user-panel");
    rewards.append(make("h3", "", "Event rewards"));
    emptyState.append(suggestions, rewards);
    Promise.all([loadSuggestions(suggestions), loadRewards(rewards)]).catch((error) => {
      showState(emptyState, error instanceof Error ? error.message : "Sign in to view your event activity.", true);
      if (error?.code === "AUTH_REQUIRED") {
        const link = make("a", "outline-link", "Open your account");
        link.href = "account.html";
        emptyState.append(link);
      }
    });
  }
})();
