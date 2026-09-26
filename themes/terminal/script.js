function terminalText(key, fallback, variables = {}) {
  const messages = window.sblogI18n;
  const text = typeof messages?.[key] === "string" ? messages[key] : fallback;
  return text.replace(/\{([A-Za-z_][A-Za-z0-9_.-]*)\}/g, (placeholder, name) => (
    Object.prototype.hasOwnProperty.call(variables, name) ? String(variables[name]) : placeholder
  ));
}

function initTerminal() {
  const term = document.querySelector(".terminal");
  const output = document.querySelector("#output");
  const input = document.querySelector("#input");
  const shown = document.querySelector("#input-text");
  const ghost = document.querySelector("#ghost-text");
  const scan = document.querySelector("#scanlines");
  if (!term || !output || !input || !shown || !ghost || !scan) return;

  const history = [];
  let historyIndex = 0;
  const routes = {
    home: term.dataset.home,
    tags: term.dataset.tags,
    links: term.dataset.links,
    archives: term.dataset.archives,
  };
  const commands = ["help", "ls", "cat", "cd", "pwd", "clear", "history", "theme", "crt", "date", "home", "tags", "links", "archives"];

  const print = (text, className = "") => {
    const line = document.createElement("div");
    line.className = `line ${className}`;
    line.textContent = text;
    output.append(line);
    output.scrollTop = output.scrollHeight;
  };

  const syncInput = () => {
    shown.textContent = input.value;
    const hit = commands.find((command) => command.startsWith(input.value) && command !== input.value);
    ghost.textContent = input.value && hit ? hit.slice(input.value.length) : "";
  };

  const switchTheme = (name) => {
    const themes = {
      phosphor: ["#7ec699", "#a8e8a8"],
      amber: ["#e8a87c", "#ffb86c"],
      cyan: ["#7aa6da", "#a8d0f0"],
    };
    const selected = themes[name];
    if (!selected) {
      print(terminalText("terminal_themes_available", "themes: phosphor, amber, cyan"), "dim");
      return;
    }
    document.documentElement.style.setProperty("--green", selected[0]);
    document.documentElement.style.setProperty("--bright", selected[1]);
    print(terminalText("terminal_theme_switched", "theme: switched to {name}", { name }), "green");
  };

  const runCommand = (raw) => {
    const value = raw.trim();
    const [command, argument] = value.split(/\s+/, 2);
    print(`visitor@devlog:~$ ${value}`, "cmd-echo");
    if (!value) return;
    if (routes[command]) {
      location.href = routes[command];
      return;
    }
    if (command === "clear") {
      output.innerHTML = "";
      return;
    }
    if (command === "pwd") {
      print("~", "green");
      return;
    }
    if (command === "date") {
      print(new Date().toLocaleString(document.documentElement.lang || undefined), "green");
      return;
    }
    if (command === "crt") {
      scan.classList.toggle("disabled");
      print(scan.classList.contains("disabled")
        ? terminalText("terminal_crt_scanlines_disabled", "CRT scanlines: disabled")
        : terminalText("terminal_crt_scanlines_enabled", "CRT scanlines: enabled"), "dim");
      return;
    }
    if (command === "history") {
      history.forEach((item, index) => print(`${String(index + 1).padStart(4)}  ${item}`, "dim"));
      return;
    }
    if (command === "theme") {
      switchTheme(argument);
      return;
    }
    if (command === "ls") {
      print("home/  tags/  links/  archives/  rss.xml", "green");
      document.querySelectorAll(".posts .post a").forEach((link) => print(`${link.textContent}.md`, "blue"));
      return;
    }
    if (command === "cd" || command === "cat") {
      print(command === "cd"
        ? terminalText("terminal_use_cd", "Use: cd tags")
        : terminalText("terminal_use_cat", "Use: open an article link from ls"), "dim");
      return;
    }
    if (command === "help") {
      print(terminalText("terminal_commands_heading", "COMMANDS"), "amber");
      print(terminalText("terminal_help_ls", "  ls                         list posts and sections"));
      print(terminalText("terminal_help_navigation", "  home|tags|links|archives   navigate site"));
      print(terminalText("terminal_help_utilities", "  clear|history|pwd          shell utilities"));
      print(terminalText("terminal_help_theme", "  theme <name>               phosphor, amber, cyan"));
      print(terminalText("terminal_help_display", "  crt|date                    display controls"));
      return;
    }
    print(terminalText("terminal_command_not_found", "{command}: command not found. Type \"help\".", { command }), "red");
  };

  input.addEventListener("input", syncInput);
  input.addEventListener("keydown", (event) => {
    if (event.key === "Enter") {
      event.preventDefault();
      if (input.value.trim()) {
        history.push(input.value.trim());
        historyIndex = history.length;
      }
      runCommand(input.value);
      input.value = "";
      syncInput();
    } else if (event.key === "Tab" && ghost.textContent) {
      event.preventDefault();
      input.value += ghost.textContent;
      syncInput();
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      if (historyIndex > 0) input.value = history[--historyIndex] || "";
      syncInput();
    } else if (event.key === "ArrowDown") {
      event.preventDefault();
      input.value = historyIndex < history.length - 1 ? history[++historyIndex] : ((historyIndex = history.length), "");
      syncInput();
    } else if (event.ctrlKey && event.key.toLowerCase() === "l") {
      event.preventDefault();
      output.innerHTML = "";
    }
  });

  document.addEventListener("click", () => input.focus());
  const updateSize = () => {
    const info = document.querySelector("#term-info");
    if (info) info.textContent = `${Math.floor(output.clientWidth / 8)}×${Math.floor(output.clientHeight / 16)}`;
  };
  addEventListener("resize", updateSize);
  updateSize();
  setTimeout(() => document.querySelector("#turn-on")?.remove(), 800);
  input.focus();
}

document.addEventListener("DOMContentLoaded", initTerminal);
