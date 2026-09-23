/**
 * LeasyBack Partner API — webhook signature verification (Node.js).
 *
 * Dependency-free: `crypto` is built in. Works on Node 16+.
 *
 * The `.cjs` extension is deliberate. It is CommonJS regardless of what the
 * project it lands in declares, so `require()` works from a CommonJS project
 * and `import { verifyLeasybackWebhook } from './verify-signature.cjs'` works
 * from an ESM one. Renaming it to `.js` inside a `"type": "module"` package
 * will break it.
 *
 * This exact file is executed by LeasyBack's test suite against a body built by
 * the real deliverer, so what you are reading is what verifies our traffic —
 * not an illustration of it.
 *
 * The four steps, in order. Step 1 is the one people skip, and it is the one
 * that makes a captured request unreplayable.
 *
 *   1. reject a timestamp further than the tolerance from your clock;
 *   2. HMAC-SHA256 over `${timestamp}.${rawBody}` with your signing secret;
 *   3. constant-time compare against the v1= value;
 *   4. drop event ids you have already processed.
 *
 * The body must be the RAW bytes as received. `JSON.stringify(req.body)` will
 * not match: key order and unicode escaping are ours, not yours. In Express
 * that means `express.raw({ type: 'application/json' })` on this route, not
 * `express.json()`.
 */

const crypto = require('crypto');

/**
 * @param {object} args
 * @param {string} args.secret            the subscription's signing secret (whsec_…)
 * @param {string} args.signatureHeader   X-LeasyBack-Signature, e.g. "v1=ab12…"
 * @param {string} args.timestampHeader   X-LeasyBack-Timestamp, Unix seconds
 * @param {string|Buffer} args.rawBody    the raw request body, unparsed
 * @param {number} [args.toleranceSeconds=300]
 * @param {number} [args.now]             override the clock; omit in production
 * @returns {boolean}
 */
function verifyLeasybackWebhook({
  secret,
  signatureHeader,
  timestampHeader,
  rawBody,
  toleranceSeconds = 300,
  now = undefined,
}) {
  // 1. Replay window. The timestamp is inside the signed material, so an
  //    attacker cannot move it forward without breaking the signature — which
  //    is what makes this check worth doing.
  if (typeof timestampHeader !== 'string' || !/^\d+$/.test(timestampHeader)) {
    return false;
  }

  const nowSeconds = now === undefined ? Math.floor(Date.now() / 1000) : now;

  if (Math.abs(nowSeconds - Number(timestampHeader)) > toleranceSeconds) {
    return false;
  }

  // 2. The signed payload is the timestamp, a literal dot, and the raw body.
  const body = Buffer.isBuffer(rawBody) ? rawBody : Buffer.from(rawBody, 'utf8');

  const expected = crypto
    .createHmac('sha256', secret)
    .update(Buffer.concat([Buffer.from(`${timestampHeader}.`, 'utf8'), body]))
    .digest('hex');

  // 3. Pull the v1 value out of the header. Splitting on "," and matching the
  //    scheme means a future second scheme sent alongside v1 does not break
  //    this code.
  for (const part of String(signatureHeader || '').split(',')) {
    const trimmed = part.trim();

    if (!trimmed.startsWith('v1=')) {
      continue;
    }

    const received = Buffer.from(trimmed.slice(3), 'utf8');
    const computed = Buffer.from(expected, 'utf8');

    // timingSafeEqual throws on a length mismatch, so check that first.
    if (received.length === computed.length && crypto.timingSafeEqual(received, computed)) {
      return true;
    }
  }

  return false;
}

module.exports = { verifyLeasybackWebhook };

/*
 * ---------------------------------------------------------------------------
 * Usage — Express
 * ---------------------------------------------------------------------------
 *
 * const express = require('express');
 * const { verifyLeasybackWebhook } = require('./verify-signature');
 *
 * const app = express();
 *
 * app.post(
 *   '/hooks/leasyback',
 *   express.raw({ type: 'application/json' }),   // NOT express.json()
 *   async (req, res) => {
 *     const ok = verifyLeasybackWebhook({
 *       secret: process.env.LEASYBACK_WEBHOOK_SECRET,
 *       signatureHeader: req.get('X-LeasyBack-Signature'),
 *       timestampHeader: req.get('X-LeasyBack-Timestamp'),
 *       rawBody: req.body,
 *     });
 *
 *     if (!ok) {
 *       return res.sendStatus(400);
 *     }
 *
 *     const event = JSON.parse(req.body.toString('utf8'));
 *
 *     // 4. Deduplicate. The event id is stable across every retry and replay,
 *     //    so this is the only thing standing between you and processing the
 *     //    same event six times when your endpoint times out.
 *     if (await alreadyProcessed(event.id)) {
 *       return res.sendStatus(200);
 *     }
 *
 *     // Answer fast, work later. We allow 10 seconds and retry on anything
 *     // that is not a 2xx.
 *     await enqueue(event);
 *
 *     return res.sendStatus(200);
 *   },
 * );
 *
 * ---------------------------------------------------------------------------
 * During a secret rotation
 * ---------------------------------------------------------------------------
 *
 * Rotating a secret keeps the previous one verifying for a grace window
 * (`previous_secret_expires_at` on the subscription). Accept either while you
 * deploy:
 *
 * const ok = [newSecret, oldSecret].some((secret) =>
 *   verifyLeasybackWebhook({ secret, signatureHeader, timestampHeader, rawBody }),
 * );
 */
