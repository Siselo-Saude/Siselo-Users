# SISELO - Backend e Banco de Dados

## Visao Geral

O **SISELO (Sistema Integrado de Saude)** e um sistema web desenvolvido para
apoiar a integracao entre o **Centro de Atencao ao Diabetes e Hipertensao
(CADH)** e as **Unidades Basicas de Saude (UBS)**.

A camada de **backend** e **banco de dados** e responsavel pelo processamento
das regras de negocio do sistema, gerenciamento das informacoes assistenciais,
controle de acesso dos usuarios e disponibilizacao das APIs consumidas pelo
frontend.

Essa camada garante a integridade, consistencia e seguranca dos dados
utilizados no acompanhamento de pacientes entre as unidades de saude.

---

## Objetivo

Centralizar e gerenciar as informacoes relacionadas ao acompanhamento de
pacientes, permitindo o compartilhamento estruturado de dados entre **CADH** e
**UBS**, garantindo continuidade do cuidado e rastreabilidade das informacoes.

---

## Responsabilidades do Backend

A camada de backend e responsavel por:

- processamento das requisicoes do sistema
- aplicacao das regras de negocio
- validacao de dados recebidos
- gerenciamento de autenticacao e autorizacao de usuarios
- comunicacao com o banco de dados
- disponibilizacao dos endpoints da API em `/api`
- registro de logs e auditoria das acoes realizadas no sistema

---

## Funcionalidades

O backend oferece suporte as seguintes funcionalidades principais:

- cadastro e gerenciamento de pacientes
- registro de atendimentos realizados
- gestao de planos de cuidado
- controle de transicoes de pacientes entre unidades
- administracao de usuarios e permissoes de acesso
- registro de auditoria das operacoes do sistema

---

## Banco de Dados

O sistema utiliza um **banco de dados relacional** responsavel por armazenar e
organizar todas as informacoes utilizadas pela aplicacao.

Entre os principais dados armazenados estao:

- informacoes cadastrais de pacientes
- historico de atendimentos
- planos de cuidado
- usuarios do sistema
- permissoes de acesso
- registros de auditoria

O banco de dados garante consistencia, organizacao e recuperacao eficiente das
informacoes do sistema.

---

## Tecnologias Utilizadas

- **PHP** - implementacao da logica de negocio e das APIs do sistema
- **MySQL** - gerenciamento e armazenamento dos dados
- **Apache** - servidor HTTP utilizado no ambiente Docker

---

## Separacao com o Frontend

O frontend oficial do sistema fica no repositorio separado
**Siselo-Frontend**.

Neste repositorio, o backend mantem as rotas de API em:

```text
/api
```

As telas PHP antigas foram mantidas apenas como legado, mas as rotas publicas
de interface redirecionam para o frontend separado.

A origem do frontend e configurada pela variavel de ambiente:

```text
FRONTEND_ORIGIN=http://localhost:3000
```

---

## Estrutura da Documentacao

A documentacao tecnica do backend e banco de dados esta organizada da seguinte
forma:

docs/ documentacao tecnica do sistema
modulos/ descricao dos modulos e funcionalidades
diagramas/ diagramas de arquitetura e fluxos do sistema

---

## Desenvolvimento

Para executar o ambiente completo com `docker compose`, os repositorios
`Siselo-Users` e `Siselo-Frontend` devem ficar lado a lado na mesma pasta:

```text
alguma-pasta/
  Siselo-Users/
  Siselo-Frontend/
```

O servico `frontend` do `docker-compose.yml` usa o volume relativo:

```text
../Siselo-Frontend:/app
```

Isso deixa a configuracao portavel entre maquinas, desde que essa estrutura de
pastas seja mantida. O caminho absoluto mostrado pelo Docker em comandos como
`docker compose config` muda de acordo com cada maquina e nao deve ser copiado
manualmente para o arquivo.

Para subir o backend com o banco de dados e o frontend integrado, execute o
comando abaixo a partir da pasta `Siselo-Users`:

```bash
docker compose up --build
```

Depois disso, os acessos esperados sao:

```text
Backend: http://localhost:8086
API: http://localhost:8086/api
Frontend: http://localhost:3000
```

## Entrada via Coopere

O fluxo de entrada Coopere usa um token assinado com HMAC SHA-256. A Coopere
deve redirecionar o usuario para o frontend do SISELO com um token:

```text
https://homologacao.sds.unb.br/coopere-login.html?token=TOKEN
```

O payload assinado deve conter:

```json
{
  "username": "usuario.coopere",
  "name": "Nome Completo",
  "email": "usuario@unb.br",
  "groups": ["Teste - UBS/CADH"],
  "iat": 1781790000,
  "exp": 1781790900
}
```

Quando a API real da Coopere/eXo estiver configurada no Siselo, o token pode
conter apenas o `username`, porque o backend consulta os dados oficiais via API:

```json
{
  "username": "usuario.coopere",
  "iat": 1781790000,
  "exp": 1781790900
}
```

Variaveis de ambiente usadas pelo backend:

```text
COOPERE_TOKEN_SECRET=segredo_compartilhado_com_a_coopere
COOPERE_ALLOWED_GROUP=Teste - UBS/CADH
COOPERE_GROUP_ID=160
COOPERE_API_BASE_URL=https://coopere.exemplo.br/portal/rest
COOPERE_API_USERNAME=usuario_de_servico
COOPERE_API_PASSWORD=senha_do_usuario_de_servico
COOPERE_API_TIMEOUT_SECONDS=10
COOPERE_TOKEN_TTL_SECONDS=900
COOPERE_DEV_MODE=0
```

Com `COOPERE_API_BASE_URL`, `COOPERE_API_USERNAME` e `COOPERE_API_PASSWORD`
preenchidos, o Siselo chama:

```text
GET /v1/users/{username}
GET /v1/users/{username}/memberships
```

Essas consultas carregam `email`, `fullName` e confirmam se o usuario pertence
ao grupo configurado em `COOPERE_GROUP_ID`. Esse valor pode ser o id numerico
do espaco/grupo informado pela Coopere, por exemplo `160`.

No Docker local, `COOPERE_DEV_MODE=1` ja permite testar o fluxo sem a Coopere
real acessando:

```text
http://localhost:3000/coopere-login.html?dev=1
```

Se os repositorios estiverem em pastas diferentes, com outro nome, ou se
apenas o `Siselo-Users` for clonado, o container `frontend` nao encontrara o
arquivo `server.js` e nao iniciara corretamente.

Este repositorio e destinado a **documentacao tecnica do backend e do banco de
dados do sistema SISELO**.

Nao devem ser incluidos no repositorio:

- dados reais de pacientes
- credenciais de acesso
- informacoes sensiveis do sistema
