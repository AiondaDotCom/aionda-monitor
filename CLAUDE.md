# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Aionda Monitor, an independent GPLv2 fork of Nagios Core 4.x: a host/service monitoring daemon written in C, plus C CGI programs and a PHP/HTML web UI.

## Build

Autoconf-based. `Makefile.in` files are templates; `configure` generates the real Makefiles (and `include/config.h`, `lib/iobroker.h`, `sample-config/*` etc. from `*.in` files). After editing `configure.ac`, regenerate `configure` with `autoconf` and commit both.

```sh
./configure --enable-testing          # add --enable-libtap to build the C TAP tests
make all                              # daemon (base/nagios), CGIs, html, workers, modules
make nagios | make cgis | make html   # individual pieces
make clean / make distclean / make devclean
```

Useful install targets (see `make` with no target for the full list): `install`, `install-config`, `install-webconf`, `install-webconf-formauth`, `install-daemoninit`, `install-commandmode`, `fullinstall`.

Code style: `make indent` (runs `indent-all.sh` → `indent.sh`, which uses `astyle --style=banner --indent=tab --unpad-paren --pad-oper`). Tabs for indentation.

Version bumps go through `./update-version <version|newdate> [date]`, which rewrites version strings/dates across C headers, PHP, docs, and spec files.

## Tests

CI (`.github/workflows/test.yml`, Ubuntu 22.04/24.04) runs `./configure --enable-testing && make test`. `make test` runs four suites:

- **lib unit tests** — `cd lib && make test`. Each `lib/foo.c` with a `lib/test-foo.c` builds `test-foo`; run one with `cd lib && make test-foo && ./test-foo`.
- **CGI unit tests** — `cd cgi && make test` (`test-mcputils`, `test-mcpconfig`; same `lib/t-utils.h` helpers).
- **Perl tests** (`make test-perl`) — `t/*.t` run with `prove`, invoking the compiled CGIs with CGI env vars against fixture config in `t/etc` and `t/var`. Single test: `cd t && prove ./610cgistatus.t :: <abs top builddir>` (needs `make cgis` and the `bin/etc/var` links/copies that `make test` creates in `t/`).
- **C TAP tests** (`make test-tap`) — `t-tap/test_*.c`, only built if configured with `--enable-libtap`. Each test links selected real `base/`/`common/` objects plus `stub_*.c` files that stub out everything else; when a test gains a new dependency, update its link line in `t-tap/Makefile.in` (or add a stub). Single test: `cd t-tap && make test_macros && ./test_macros`. `test_macros` and `test_checks` are also run under valgrind if available.

`make coverage` produces an lcov report.

## Architecture

- **`base/`** — the `nagios` daemon (compiled with `-DNSCORE`). `nagios.c:main` parses config, spawns worker processes, initializes NEB modules and the query handler, then enters `event_execution_loop()` in `events.c`, a scheduler driven by a squeue (priority queue of timed events) and the iobroker (poll-based fd multiplexer).
  - Checks, notifications and event handlers are never forked directly by the core; they're sent to **worker processes** (`workers.c`, `wproc_run_check()`), which are forked copies of `nagios` running `lib/worker.c` and talk to the core over sockets using `kvvec` key/value messages. Results come back asynchronously and are processed in `checks.c`.
  - `query-handler.c` exposes a UNIX socket (`@wproc`, `@nerd`, `@core` etc. handlers); `nerd.c` is the event-streaming handler on it.
  - `broker.c` / `nebmods.c` implement the **Nagios Event Broker (NEB)**: dynamically loaded modules (`module/helloworld.c` is the example) receive callbacks defined in `include/nebcallbacks.h` / `nebstructs.h`.
- **`common/`** — code shared by the daemon and CGIs (object model `objects.c`, `macros.c`, `statusdata.c`, comments, downtime). These files are compiled **twice**: as `*-base.o` with `-DNSCORE` for the daemon and with `-DNSCGI` for the CGIs, so `#ifdef NSCORE`/`NSCGI` blocks select behavior. Check both paths when editing.
- **`xdata/`** — default file-format backends: `xodtemplate.c` (object config parsing, template inheritance), `xsddefault.c` (status.dat), `xrddefault.c` (retention.dat), `xcddefault.c` (comments), `xpddefault.c` (perfdata). Also compiled into both daemon and CGIs.
- **`lib/`** — `libnagios`: standalone, generic data structures and I/O helpers (squeue/prqueue, dkhash, skiplist, bitmap, kvvec, iobroker, iocache, nsock, runcmd, worker). No dependency on the rest of the tree; installed via `install-devel`.
- **`cgi/`** — separate C CGI binaries (see **MCP server** below for `mcp.cgi`) (`status.cgi`, `cmd.cgi`, `extinfo.cgi`, …) plus JSON APIs (`statusjson`, `objectjson`, `archivejson`). They don't talk to the daemon: they read `cgi.cfg`, the cached object file, `status.dat` and log archives from disk, and submit commands by writing to the external command FIFO. Authorization is in `cgiauth.c`, based on `REMOTE_USER`.
- **`html/`** — PHP/static frontend, installed alongside the CGIs. See **Web UI** below. (`install-exfoliation`/`install-classicui` overwrite the stylesheets with the old themes and would undo the redesign.)
- **`include/`** — headers; `*.h.in` are generated by configure (`config.h`, `locations.h`).
- **`sample-config/`** — `*.in` templates substituted by configure (`monitor.cfg`, `cgi.cfg`, `httpd.conf`, `httpd-formauth.conf`, object templates). Generated outputs are gitignored.
- **`startup/`** — init script templates (sysv, systemd, openrc, upstart).
- **`tap/`** — bundled libtap (its own autotools project) used by `t-tap/`.

## Web UI

The web UI was redesigned (modern dark/light theme, mobile friendly). Background, decisions and open issues: `docs/webui-redesign.md`.

- **Shell** — `html/index.php.in` (generated to `index.php` by configure) renders the sidebar navigation inline and shows every page in a single `<iframe name="main">`. On load of each framed page it adds `in-shell` and the forced theme class to the page's `<html>`, wraps tables that overflow in `.table-scroll`, highlights the active menu entry and mirrors the page title into the mobile top bar. Below 900px the sidebar becomes a drawer. Problem badges in the menu come from `statusjson.cgi?query=hostcount|servicecount`.
- **Navigation** — defined once in `html/includes/nav.inc.php` (`print_nav()`), used by the shell and the standalone `side.php`.
- **Theme** — `$cfg['theme']` in `config.inc.php` is `auto` (default, follows the OS via `prefers-color-scheme`), `dark` or `light`. PHP pages print `<html class="">` for auto. The sidebar toggle cycles auto → dark → light and stores the choice in `localStorage` (`nagios-theme`).
- **CSS** — `html/stylesheets/common.css` holds all design tokens (`--bg`, `--surface`, `--text`, `--muted`, `--link`, status colors `--ok/--warning/--unknown/--critical/--unreachable/--pending` plus `-soft` tints, `--accent`/`--accent-fg`) and the shared components (data tables, status badges, header/info box, totals, forms, messages, nav). Page stylesheets (`status.css`, `tac.css`, `extinfo.css`, …; the report CGIs share `reports.css` via `@import`) must use only these tokens for colors so both themes work. Light mode uses darker status text colors than dark mode; soft tints are always mixed from the bright hues.
- **CGI HTML** — CGIs print no doctype (quirks mode: tables don't inherit font size, hence `table { font-size: inherit }`) and no viewport meta; they are made mobile friendly only through the shell's iframe. Style them with page-scoped selectors (`body.status`, `body.extinfo`, …) rather than changing the C output. Chrome drops a whole rule that nests `:has()` inside `:has()`.
- **Form login** — `html/login.php` + `sample-config/httpd-formauth.conf.in` (`make install-webconf-formauth`) replace the HTTP Basic Auth dialog with Apache `mod_auth_form` (needs `auth_form request session session_cookie session_crypto`). Users still come from `htpasswd.users`; `/nagios/logout` ends the session. The logout menu entry is only shown when `AUTH_TYPE` is `form`.
- **Local preview** — there is no bundled container setup. A Debian image with `apache2 libapache2-mod-php`, `./configure --with-command-group=nagios --with-httpd-conf=/etc/apache2/conf-enabled && make all && make install install-commandmode install-config install-webconf-formauth` plus `htpasswd -c …/htpasswd.users nagiosadmin` works; copy changed `html/` files into `/usr/local/nagios/share` to iterate without rebuilding.

## MCP server

`cgi/mcp.cgi` is a Model Context Protocol server for AI assistants (Streamable HTTP, stateless: one JSON-RPC message per POST). User documentation: `docs/mcp-server.md`.

- **Files** — `cgi/mcp.c` (HTTP, JSON-RPC, auth, the tool table and tools), `cgi/mcputils.c` (JSON parser/serializer, string buffer, SHA-256, token file lines, scopes, time parsing, field validation), `cgi/mcpconfig.c` (object config parser, surgical block edits, unified diff). Headers in `include/mcputils.h`, `include/mcpconfig.h`. Pure logic lives in the utils files so it can be unit tested.
- **Auth** — bearer tokens (`nagmcp_…`), stored as SHA-256 in the token file (`mcp_token_file`, default next to `cgi.cfg`). Each token acts as a Nagios user: `REMOTE_USER` from the web server is ignored and replaced by the token's user before `get_authentication_information()`, so all `is_authorized_for_*` checks apply. Scopes (`read`, `write`, `admin`, `config`) only narrow that; `config` is not implied by `admin`. `mcp.cgi --create-token|--list-tokens|--revoke-token` manages tokens on the command line.
- **Read tools** run `statusjson.cgi` / `objectjson.cgi` / `archivejson.cgi` as a child process with the token's user (`json_cgi()`), then trim the result. Don't duplicate data access in `mcp.c`.
- **Write tools** submit external commands through `submit()`; check rights with `can_write()`, `auth_host_cmd()`, `auth_svc_cmd()`, `auth_hostgroup_cmd()`, `auth_system_cmd()`. Fields must pass `mcp_safe_field()` (no `;`, no line breaks); free text goes through `mcp_clean_text()`. `CHANGE_*` commands stay forbidden, as in `cmd.cgi`.
- **Config tools** (`plan_config_change` / `apply_config_change` / `restore_config_backup`) load every object file from `monitor.cfg` into a workspace, edit only the affected `define` block, validate with `nagios -v` on a staged copy in `/tmp`, and on apply require the `plan_id` (SHA-256 over the diff and the on-disk content), back up to `mcp-backups/`, write via temp file + rename and submit `RESTART_PROCESS`. Existing files that are not writable must never be replaced. Off unless `mcp_allow_config_changes=1`; command definitions additionally need `mcp_allow_command_changes=1`.
- **Adding a tool** — write `tool_<name>(const mj *args, mcp_buf *err)` returning an `mj` object (or NULL with a message in `err`, which becomes an `isError` result), add an entry to the `tools[]` table with scope, read-only/destructive flags, an English description written for the model and a JSON Schema with a description for every property, then cover it in `t/630mcp.t`. `tools/list` and `tools/call` both go through `tool_available()`.
- **Tests** — `cd cgi && make test` runs the C unit tests (`test-mcputils`, `test-mcpconfig`, using `lib/t-utils.h`); `t/630mcp.t` runs `mcp.cgi` like a web server against a private copy of the `t/etc` fixtures, reads back what was written to the command file, and exercises the config workflow with the real `base/nagios -v` (the `t/etc/minimal.cfg` fixture itself does not pass `nagios -v`, so the test writes its own small valid object file). Both run in `make test`.
