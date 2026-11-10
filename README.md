# Truck Routing API

API REST pentru calcularea rutelor optime pentru camioane, cu evitarea automată a drumurilor înguste, rezidențiale sau a zonelor restricționate.

Construit cu **PHP 8.3**, **Valhalla** (motor de rutare open-source) și **Docker**.

---

## Cuprins

- [Cum funcționează](#-cum-funcționează)
- [Structura proiectului](#-structura-proiectului)
- [Cerințe](#-cerințe)
- [Instalare și pornire](#-instalare-și-pornire)
- [Configurare](#-configurare)
- [Utilizare (API)](#-utilizare-api)
- [Harta interactivă](#-harta-interactivă)

---

## Cum funcționează

1. Clientul trimite o cerere `POST` cu coordonatele de start, destinație și parametrii camionului (greutate, înălțime, lățime).
2. API-ul transmite cererea către **Valhalla**, care calculează ruta optimă (+ 2 rute alternative).
3. Fiecare rută este analizată: se verifică dacă trece prin drumuri de clasă joasă (rezidențiale, service etc.).
4. Dacă o rută e neconformă, API-ul încearcă automat să o re-calculeze excludând segmentele problematice (până la 4 încercări).
5. Se returnează ruta (sau rutele) care respectă restricțiile pentru camioane.

```
Client  →  POST /api/routes  →  PHP API  →  Valhalla (motor rutare)
                                   ↑               ↓
                              Analiză rută  ←  Rută calculată
                              (clase drum, zone restricționate)
```

---

## Structura proiectului

```
truck-routing-api/
├── config/
│   ├── app.php                  # Configurare generală (URL Valhalla, căi fișiere)
│   └── restricted_roads.json    # Zone restricționate definite ca poligoane
├── docker/
│   ├── php/
│   │   └── Dockerfile           # Imagine Docker pentru API-ul PHP
│   └── valhalla/
│       └── custom_files/        # Fisierele OSM si tile-urile se pun MANUAL aici
│           └── valhalla.json    # Configurarea motorului Valhalla
├── public/
│   ├── index.php                # Entry point al API-ului (router HTTP)
│   ├── map.html                 # Harta interactivă pentru testare
│   └── style.css                # Stiluri pentru hartă
├── src/
│   ├── Controllers/
│   │   └── RouteController.php  # Primește și procesează cererile HTTP
│   ├── Services/
│   │   └── RoutingService.php   # Logica principală: calcul rută + analiză
│   ├── Validators/
│   │   └── RouteValidator.php   # Validarea datelor de intrare
│   └── Exceptions/              # Excepții personalizate
├── tests/                       # Teste automate
├── .env.example                 # Template pentru variabilele de mediu
├── composer.json                # Dependențe PHP
└── docker-compose.yml           # Orchestrare servicii Docker
```

---

## Cerinte

- [Docker](https://www.docker.com/) și **Docker Compose**
- Un fișier **OSM PBF** pentru regiunea dorită (ex: descărcat de pe [Geofabrik](https://download.geofabrik.de/))

> **Nu** este nevoie de PHP sau Composer instalate local — totul rulează în Docker.

---

## Instalare si pornire

### 1. Clonează repository-ul

```bash
git clone https://github.com/<user>/truck-routing-api.git
cd truck-routing-api
```

### 2. Configurează variabilele de mediu

```bash
cp .env.example .env
```

Editează `.env` dacă ai nevoie să schimbi porturile sau URL-ul Valhalla.

### 3. Adaugă fișierul OSM pentru regiunea ta

Descarcă un fișier `.osm.pbf` de pe [Geofabrik](https://download.geofabrik.de/) și pune-l în:

```
docker/valhalla/custom_files/
```

> Exemplu: `docker/valhalla/custom_files/romania-latest.osm.pbf`

### 4. Pornește serviciile

```bash
docker compose up -d
```

La prima pornire, Valhalla va **genera automat** tile-urile de rutare din fișierul OSM. Acest proces poate dura **câteva minute** în funcție de dimensiunea fișierului.

Poți urmări progresul cu:

```bash
docker logs -f truck_routing_valhalla
```

### 5. Verifică că funcționează

```bash
curl http://localhost:8000/api/health
```

Răspuns așteptat:
```json
{
    "success": true,
    "message": "API is working!",
    "version": "1.0.0"
}
```

---

## Configurare

### Fișierul `.env`

| Variabilă | Valoare implicită | Descriere |
|-----------|------------------|-----------|
| `APP_ENV` | `development` | Mediul de rulare (`development` / `production`) |
| `APP_PORT` | `8000` | Portul pe care ascultă API-ul |
| `VALHALLA_URL` | `http://valhalla:8002` | URL-ul intern al serviciului Valhalla |
| `RESTRICTED_ROADS_PATH` | `config/restricted_roads.json` | Calea către fișierul cu zone restricționate |

### Zone restricționate (`config/restricted_roads.json`)

Poți defini zone geografice pe care camioanele nu au voie să le traverseze, folosind poligoane GeoJSON:

```json
[
  {
    "type": "polygon",
    "name": "Zona pietonală centru",
    "polygon": [
      { "lat": 46.770, "lon": 23.590 },
      { "lat": 46.771, "lon": 23.592 },
      { "lat": 46.769, "lon": 23.593 },
      { "lat": 46.770, "lon": 23.590 }
    ]
  }
]
```

---

## Utilizare (API)

### `GET /api/health` — Verificare stare API

```bash
curl http://localhost:8000/api/health
```

---

### `POST /api/routes` — Calculare rută camion

**Headers:**
```
Content-Type: application/json
```

**Body:**

| Câmp | Tip | Obligatoriu | Descriere |
|------|-----|-------------|-----------|
| `start.lat` | float | DA | Latitudine punct de start |
| `start.lon` | float | DA | Longitudine punct de start |
| `end.lat` | float | DA | Latitudine destinatie |
| `end.lon` | float | DA | Longitudine destinatie |
| `end.max_access_m` | float | NU | Distanta maxima (metri) pe drumuri mici langa destinatie (implicit: 1500m) |
| `truck.weight` | float | NU | Greutatea camionului in **tone** (implicit: 12t) |
| `truck.height` | float | NU | Inaltimea camionului in **metri** (implicit: 4.0m) |
| `truck.width` | float | NU | Latimea camionului in **metri** (implicit: 2.5m) |
| `avoid_zones` | array | NU | Zone dinamice de evitat (array de poligoane cu `lat`/`lon`) |

**Exemplu cerere:**

```bash
curl -X POST http://localhost:8000/api/routes \
  -H "Content-Type: application/json" \
  -d '{
    "start": { "lat": 46.7712, "lon": 23.6236 },
    "end":   { "lat": 46.7489, "lon": 23.5890 },
    "truck": {
      "weight": 18,
      "height": 4.2,
      "width": 2.55
    },
    "end": {
      "lat": 46.7489,
      "lon": 23.5890,
      "max_access_m": 500
    }
  }'
```

**Răspuns de succes (`200`):**

```json
{
    "success": true,
    "data": {
        "routes": [
            {
                "summary": {
                    "length": 5.234,
                    "time": 720
                },
                "legs": [...]
            }
        ],
        "warnings": []
    }
}
```

**Răspuns fără rută validă (`404`):**

```json
{
    "success": false,
    "message": "Nu a fost găsită nicio rută validă care să respecte restricțiile pentru camioane.",
    "data": { "routes": [] }
}
```

---

## Harta interactiva

Deschide în browser:

```
http://localhost:8000/map.html
```

Interfața grafică îți permite să selectezi punctul de start și destinație direct pe hartă și să vizualizezi ruta calculată.

---

## Dependente principale

| Pachet | Versiune | Rol |
|--------|----------|-----|
| `php` | ^8.3 | Limbajul de bază |
| `guzzlehttp/guzzle` | ^7.x | Client HTTP pentru comunicarea cu Valhalla |
| `vlucas/phpdotenv` | ^5.x | Încărcarea variabilelor din fișierul `.env` |
| `valhalla` (Docker) | latest | Motor de rutare open-source |

