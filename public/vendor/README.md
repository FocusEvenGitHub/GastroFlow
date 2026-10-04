# public/vendor — bibliotecas de terceiros servidas localmente

Spec 055. O caixa, a cozinha e o Admin não dependem de CDN: sem internet (só a rede local), as telas continuam funcionando. Não há build nem Node em `public/` — cada arquivo abaixo é a distribuição oficial, **byte a byte igual** à URL de origem, com versão fixa.

| Biblioteca | Versão | Licença | Origem |
|---|---|---|---|
| Bootstrap | 5.3.0 | MIT | `https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/` (`css/bootstrap.min.css`, `js/bootstrap.bundle.min.js`) |
| Font Awesome Free | 6.4.0 | ícones CC BY 4.0 · fontes SIL OFL 1.1 · código MIT | `https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/` (`css/all.min.css` + os `webfonts/` que ele referencia) |
| Alpine.js | 3.17.4 | MIT | `https://unpkg.com/alpinejs@3.17.4/dist/cdn.min.js` |
| Tom Select | 2.6.2 | Apache-2.0 | `https://cdn.jsdelivr.net/npm/tom-select@2.6.2/dist/` (`js/tom-select.complete.min.js`, `css/tom-select.bootstrap5.min.css`) |
| Chart.js | 4.4.0 | MIT | `https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js` |
| Inter (variável, subconjunto latin) | @fontsource-variable/inter 5.3.0 | SIL OFL 1.1 (`inter-5.3.0/LICENSE`) | `https://cdn.jsdelivr.net/npm/@fontsource-variable/inter@5.3.0/` (`files/inter-latin-wght-normal.woff2`, `LICENSE`); declarada por `@font-face` em `/assets/css/style.css` |

Alpine (`3.x.x`) e Tom Select (`@2`) eram faixas flutuantes no CDN; foram fixados na versão que essas faixas resolviam em 2026-10-03, ou seja, a que já rodava.

Fora daqui, de propósito: o Swagger UI de `/api/docs/` continua no unpkg — é página de desenvolvedor, não de operação.

## SHA-256

```text
232519394c6c8fdba6f362b1d9da16106db513cdbf899011f00daab4051df31c  alpinejs-3.17.4/cdn.min.js
7f1d37f0d90b6385354c2ac10e2bb91563c46bd7a266ed351222ebcac8496c2a  bootstrap-5.3.0/css/bootstrap.min.css
aa53d582f97eb594c2a5cc5824574707f9ba9837bce3046bfa5f3556860f4e04  bootstrap-5.3.0/js/bootstrap.bundle.min.js
0e2326c6868072bec1592760c6729043caeea2960a2b46cee6a2192aac6abff0  chartjs-4.4.0/chart.umd.min.js
1edb1725a9ea8ca4dcf2f5508cee183218aa1685e47c1b23056717f754f58ebf  fontawesome-free-6.4.0/css/all.min.css
20c4a58bc9d1d69e935d06f1528923646a715be5e218665655cade8f5f1b8c00  fontawesome-free-6.4.0/webfonts/fa-brands-400.ttf
748332090c4b8e20f95d0ff59f0be20fa9c889359d3b36d4b886d73376054207  fontawesome-free-6.4.0/webfonts/fa-brands-400.woff2
528d022dce6725f8a0811fd91d8e6513445c81ef33353a5c3234eab932551abf  fontawesome-free-6.4.0/webfonts/fa-regular-400.ttf
8e7e5ea1b15f62ab14dbd41768e8fbcd21cc859a4ea5da812457ee714299fb35  fontawesome-free-6.4.0/webfonts/fa-regular-400.woff2
67a65763c7f80903d81603bbeb9049fc2bf28508479b83ed011fe24c71fa950a  fontawesome-free-6.4.0/webfonts/fa-solid-900.ttf
7152a6933ee3d690ec2af3d09da9d701723d16aa3410a6d80f28ff8866f3b880  fontawesome-free-6.4.0/webfonts/fa-solid-900.woff2
0515a423f828ce4e6accf92a2ea0b03d19d31cc86d9af0373291e1fd4db5f348  fontawesome-free-6.4.0/webfonts/fa-v4compatibility.ttf
694a17c3d9d6c05f8aac63c544615552a4b220e9a4de863d87341a6bcfc1bc8d  fontawesome-free-6.4.0/webfonts/fa-v4compatibility.woff2
3b0a5fca3d17942cde889069889dedbbbd075e9b599968c82a95f4d944e9b345  inter-5.3.0/LICENSE
3100e775e8616cd2611beecfa23a4263d7037586789b43f035236a2e6fbd4c62  inter-5.3.0/inter-latin-wght-normal.woff2
ce467598f8953044ddfc0ff9fd4b7e2f6cced549001737d6b712a14f8b9a6599  tom-select-2.6.2/css/tom-select.bootstrap5.min.css
8f9d7d3420e938c2c9817818b56df43ea46feae5bf1f5a2e828863c034df0e37  tom-select-2.6.2/js/tom-select.complete.min.js
```

Conferir: `cd public/vendor && sed -n '/^```text$/,/^```$/p' README.md | grep -v '```' | sha256sum -c`

## Atualizar uma biblioteca

1. Baixe a nova versão, da mesma origem, para um diretório **novo** `<lib>-<versão>/` (a versão no nome do diretório é o que impede o navegador de misturar arquivo velho em cache com página nova).
2. Troque as referências (`grep -rn "/vendor/<lib>-" public`).
3. Atualize a tabela e os SHA-256 acima; apague o diretório antigo.
4. Rode a suíte de navegador — `tests/e2e/specs/offline-assets.spec.ts` falha se alguma tela voltar a pedir algo fora do próprio servidor.
