# Images

The files here are on-brand SVG placeholders. The site looks finished with
them as-is, so you can launch without waiting on a photographer.

**Replace them with real photos as soon as you can.** For this business,
a phone snapshot of your actual dish on your actual roof sells harder
than any stock image — people in the compound want to see the thing they
are buying from, and outsiders want proof it exists.

## How to swap one in

1. Drop your photo in this folder, e.g. `hero-dish.jpg`.
2. Change the one `src` in the HTML that points at the placeholder.

```
public_html/index.html   →  assets/img/hero-dish.svg   →  assets/img/hero-dish.jpg
```

That is the whole job — the CSS crops and rounds whatever you give it.

## What each slot wants

| File | Where | Shoot this |
|---|---|---|
| `hero-dish.svg` | Top of the homepage | The Starlink dish on the roof, late afternoon or dusk. Sky in frame. |
| `compound.svg` | Coverage section | The compound from the gate, or the building the dish serves. Evening, lights on. |
| `step-1-signup.svg` | How it works | Someone's hands holding a phone on the signup screen. |
| `step-2-pay.svg` | How it works | A transfer receipt on screen, or the bank app confirmation. |
| `step-3-browse.svg` | How it works | Someone using their phone or laptop, relaxed, indoors. |
| `gallery-*.svg` | Gallery strip | The router on the wall, cabling, the installer at work, people online. |

## Sizes

Aim for **1600px on the long edge**, JPEG, quality 80. Anything bigger
just costs your customers data on a connection they are paying for.
The hero wants 4:3, the step images 16:10, and the gallery squares 1:1 —
but the CSS uses `object-fit: cover`, so nothing breaks if you are off.
