# src/Domain/Models — acceso a datos

Un Model por tabla, extendiendo `App\Core\ModelBase`. Aquí (y solo aquí) puede haber SQL.

## Plantilla

```php
namespace App\Domain\Models;
use App\Core;

class Page extends Core\ModelBase {
  static public $tablename = 'pages';
  static public $defaultorder = ' pages.title ASC ';            // opcional
  static public $joins = [                                      // opcional
    // [tablaJoin, fkLocal, pkRemota, [columnas a traer], alias?, tablaOrigen?, condiciónExtra?]
    ["typespage", "typepageid", "idtypepage", ["typepage"]],
  ];
  static public $combos = [                                     // opcional: añade "status_label"
    "status" => [["id"=>1,"label"=>"Publicada"],["id"=>0,"label"=>"Borrador"]],
  ];
}
```

## dbschema.php manda

`ModelBase` no consulta la BBDD para saber columnas: lee `$GLOBALS['dbschema'][tabla]`.

| Propiedad | Efecto en ModelBase |
|---|---|
| `type` | `int`/`bigint`/`float` se castean; `varchar` se trunca a `length`; `json` se codifica al guardar y se **decodifica al leer** (`enrichvalues`). |
| `primary` | `get()`, `update()`, `delete()`, `exists()` filtran por ellas (soporta compuestas). |
| `autoincrement` | Se elimina del INSERT; `insert()` devuelve el id nuevo. |
| `required` | `validate()` exige la clave en el alta (si falta: `echo` + `die`, herencia; ver aprendizajes). |
| `default` | `setdefaults()` la rellena en el alta. |
| `maincol` | Etiqueta de `getascombo()` y orden por defecto. |
| `ordercol` (1,2,3) | Orden por defecto si no hay `$defaultorder`. |
| `created`/`updated` (columnas) | `auditdates()` pone `date('Ymd')` automáticamente. |

**Columna no declarada = invisible**: `insert/update/search` la ignoran sin error. **Regla "solo añadir"**: nunca borrar ni cambiar propiedades fijadas a mano.

## API de ModelBase

| Método | Notas |
|---|---|
| `get($db, $id)` / `get($db, ['pk'=>v])` | Fila enriquecida o `[]`. Incluye columnas de `$joins`. |
| `search($db, $filtros)` | `['col'=>v]`, `['col'=>[v1,v2]]` (IN) o `['clave'=>['mode'=>'LIKE','field'=>'col','table'=>'t','values'=>v]]`. Modos: EQUAL, NOT_EQUAL, LIKE, GT, LT, GTE, LTE, IN, BETWEEN. |
| `insert($db, $item)` | auditdates → filterfields → defaults → validate. Devuelve id (o fila si PK compuesta). |
| `update($db, $item)` | Requiere las primarias en `$item`. Solo actualiza columnas presentes (y no-null). |
| `upsert($db, $item)` | `exists()` ? update : insert. |
| `delete($db, $item)` | `$item` con las primarias (normalmente el resultado de `get`). |
| `count($db, $filtros)` | |
| `getascombo($db)` / `getcombos($db)` | `[['id','label'],…]` para selects. |

Gotchas:
- `update()` ignora valores `null` (usa `isset`): para poner NULL, método propio con SQL (`TypeUser::clearpermissions`, `User::setdeadline2fa`).
- Todo llega como **string** (PDO stringify): compara con `==` o castea.
- `key` y otras palabras reservadas: cualificar (`settings.key`) o con comillas invertidas.
- Para lógica extra al leer, sobrescribe `search()` y enriquece con una segunda consulta (ejemplo: `User::search` añade `lastaccess`).

## Models incluidos

`User` (login con migración md5→password_hash, `setpasswordhash`, `setdeadline2fa`; `search` quita `password`), `TypeUser` (`clearpermissions`), `UserLog::record($db,$userid,$accion)`, `TwoFactorModel` (`user_2fa`, `hasUser`), `TwoFactorEmailModel` (`user_2fa_emails`, `updateAttempts`), `Setting` (`settings`).
