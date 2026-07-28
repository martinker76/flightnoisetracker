import { useState, FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api/client';
import EmailCloak from '../components/EmailCloak';

const BOX_CENTER: [number, number] = [47.974, 16.604];

type SubmitState =
  | { kind: 'idle' }
  | { kind: 'submitting' }
  | { kind: 'ok'; id: number }
  | { kind: 'err'; message: string };

/**
 * About page.
 *
 * Sections:
 *   1. Purpose
 *   2. Data Source
 *   3. Monitored Area
 *   4. Reference Point
 *   5. Airport & Runway Classification
 *   6. Noise Tracking
 *   7. Estimated Noise Levels
 *   8. Statistics
 *   9. Technical Notes
 *  10. Imprint (Impressum) — Austrian ECG §5 compliance
 *  11. Contact form + obfuscated email
 */
export default function About() {
  return (
    <div className="space-y-6 max-w-3xl">
      <h1 className="text-2xl font-bold">ℹ️ About FlightNoiseTracker</h1>

      <PurposeSection />
      <DataSourceSection />
      <MonitoredAreaSection />
      <ReferencePointSection />
      <AirportSection />
      <EstimatedNoiseSection />
      <StatisticsSection />
      <TechnicalNotesSection />
      <ImprintSection />
      <ContactSection />
    </div>
  );
}

function PurposeSection() {
  return (
    <section className="card space-y-3">
      <h2 className="text-lg font-semibold">Purpose</h2>
      <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
        FlightNoiseTracker monitors aircraft movements over{' '}
        <strong>Mannersdorf am Leithagebirge (2452)</strong>, Lower Austria, and answers one
        core question:
      </p>
      <blockquote className="border-l-4 border-blue-500 pl-4 italic text-slate-600 dark:text-slate-400">
        Is the number of flights routed across the city increasing, and are noise emissions
        growing as a result?
      </blockquote>
      <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
        The app captures every flight passing through Mannersdorf's airspace, classifies which
        runway at Vienna International Airport (LOWW / VIE) each flight is using, and provides
        daily and hourly statistics to track trends over time.
      </p>
      <p className="text-xs text-slate-500 dark:text-slate-400 italic leading-relaxed border-l-2 border-slate-300 dark:border-slate-600 pl-3 mt-2">
        <strong>Disclaimer:</strong> All data shown here is based on public sources
        (primarily the OpenSky Network community ADS-B feed) and on derived
        calculations (runway classification, distance, estimated noise). Although we
        try to be accurate, errors can occur — both in the source data and in our
        processing — and individual records may therefore be incorrect. Use the
        information with appropriate caution; nothing on this site is intended as
        aviation-grade data.
      </p>
    </section>
  );
}

function DataSourceSection() {
  return (
    <section className="card space-y-3">
      <h2 className="text-lg font-semibold">Data Source</h2>
      <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
        Flight data is provided by the{' '}
        <a
          href="https://opensky-network.org"
          target="_blank"
          rel="noopener noreferrer"
          className="text-blue-600 dark:text-blue-400 hover:underline"
        >
          OpenSky Network
        </a>
        , a community-driven ADS-B receiver network that aggregates air traffic surveillance
        data from thousands of volunteers worldwide.
      </p>
      <ul className="list-disc list-inside text-slate-700 dark:text-slate-300 space-y-1">
        <li>
          <strong>Live data</strong> &mdash; polled every 60 seconds from OpenSky's{' '}
          <code className="bg-slate-100 dark:bg-slate-700 px-1 rounded">/states/all</code>{' '}
          endpoint, filtered to the Mannersdorf bounding box.
        </li>
        <li>
          <strong>Historical data</strong> &mdash; fetched from OpenSky's arrival/departure and
          track endpoints to backfill past weeks of traffic.
        </li>
        <li>
          <strong>Aircraft metadata</strong> &mdash; type codes and model/operator information
          are resolved per-flight via the public{' '}
          <a
            href="https://api.adsb.lol/"
            target="_blank"
            rel="noopener noreferrer"
            className="text-blue-600 dark:text-blue-400 hover:underline"
          >
            ADSB.lol
          </a>{' '}
          API.
        </li>
      </ul>
    </section>
  );
}

function MonitoredAreaSection() {
  return (
    <section className="card space-y-3">
      <h2 className="text-lg font-semibold">Monitored Area</h2>
      <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
        The monitored airspace is a rectangle centered on Mannersdorf, sized to cover the
        built-up area plus a buffer where aircraft are clearly audible.
      </p>

      <div className="grid grid-cols-2 gap-4 bg-slate-50 dark:bg-slate-700/50 rounded-lg p-4">
        <div>
          <p className="text-xs text-slate-500 uppercase tracking-wider mb-1">Corner SW</p>
          <p className="font-mono text-sm">47.947°N, 16.570°E</p>
        </div>
        <div>
          <p className="text-xs text-slate-500 uppercase tracking-wider mb-1">Corner NE</p>
          <p className="font-mono text-sm">48.001°N, 16.638°E</p>
        </div>
        <div>
          <p className="text-xs text-slate-500 uppercase tracking-wider mb-1">Center (town)</p>
          <p className="font-mono text-sm">
            {BOX_CENTER[0]}°N, {BOX_CENTER[1]}°E
          </p>
        </div>
        <div>
          <p className="text-xs text-slate-500 uppercase tracking-wider mb-1">Area</p>
          <p className="font-mono text-sm">~6 km × 5 km</p>
        </div>
      </div>

      <h3 className="text-md font-medium mt-4">How the boundaries were chosen</h3>
      <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
        The box is based on <strong>audibility</strong>, not administrative borders. A typical
        jet on approach to Vienna (A320 / B737) passes over the area at roughly{' '}
        <strong>1,200&ndash;1,800 m</strong> altitude. At the box's edge, the slant distance is
        approximately <strong>3 km</strong>, producing an estimated <strong>~55 dBA</strong> at
        ground level &mdash; clearly audible above daytime background noise (~40&ndash;45 dBA).
        The box extends about halfway toward neighboring villages (Hof am Leithaberge to the SW,
        Sommerein to the NE, Götzendorf to the N) without reaching their built-up areas.
      </p>
    </section>
  );
}

function ReferencePointSection() {
  return (
    <section className="card space-y-3">
      <h2 className="text-lg font-semibold">Reference Point</h2>
      <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
        The center of Mannersdorf resolves to <strong>Mannersdorfer Schloss</strong> (the old
        castle) at <strong>Hauptstraße 48</strong> &mdash; OSM's centroid for the town. The app
        reports the <em>closest distance</em> each flight passes to this point, calculated from
        every position sample collected while the aircraft was within the bounding box.
      </p>
    </section>
  );
}

function AirportSection() {
  return (
    <section className="card space-y-3">
      <h2 className="text-lg font-semibold">Airport &amp; Runway Classification</h2>
      <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
        The app focuses exclusively on{' '}
        <strong>Vienna International Airport (LOWW / VIE)</strong> (48.1103°N, 16.5697°E,
        ~20 km north of Mannersdorf). Every flight is classified as:
      </p>
      <ul className="list-disc list-inside text-slate-700 dark:text-slate-300 space-y-1">
        <li>
          <strong>VIE-related</strong> &mdash; the flight passed within 50 km of LOWW at an
          altitude below 6,000 m, suggesting an arrival or departure.
        </li>
        <li>
          <strong>Overflight</strong> &mdash; the flight crossed the bounding box without
          approaching Vienna Airport, e.g. en-route traffic at cruise altitude.
        </li>
      </ul>
      <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
        For VIE-related flights, the runway is inferred from the aircraft's heading, altitude,
        and position at its closest approach to the airport:
      </p>
      <ul className="list-disc list-inside text-slate-700 dark:text-slate-300 space-y-1">
        <li>
          <strong>Runway 11/29</strong> &mdash; approach along a ~110° or ~290° heading
          (ESE&ndash;WNW alignment, 3,500 m).
        </li>
        <li>
          <strong>Runway 16/34</strong> &mdash; approach along a ~160° or ~340° heading
          (NNW&ndash;SSE alignment, 3,600 m).
        </li>
      </ul>
    </section>
  );
}



function EstimatedNoiseSection() {
  return (
    <section className="card space-y-3">
      <h2 className="text-lg font-semibold">Estimated Noise Levels</h2>
      <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
        Each flight includes an <strong>estimated peak noise level</strong> (L<sub>Amax</sub>,
        in dBA) calculated at the Mannersdorf town center. The model is the v1.1 multi-component
        aircraft noise estimator described in{' '}
        <code className="bg-slate-100 dark:bg-slate-700 px-1 rounded">
          SPEC-NOISE-MODEL-v1.1.md
        </code>{' '}
        (in the project repository). It accounts for the dominant physical effects that
        determine how loud an aircraft sounds on the ground.
      </p>

      <div className="bg-slate-50 dark:bg-slate-700/50 rounded-lg p-4 font-mono text-xs overflow-x-auto">
        L<sub>Amax</sub> = L<sub>ref</sub>[phase,cat] + offset
        <br />
        &nbsp;&nbsp;&minus; 20 &middot; log<sub>10</sub>(d<sub>slant</sub> / 1&nbsp;km)
        <br />
        &nbsp;&nbsp;&minus; &alpha;(phase) &middot; d<sub>slant</sub>
        <br />
        &nbsp;&nbsp;&minus; A<sub>ground</sub>
        <br />
        &nbsp;&nbsp;&minus; A<sub>screening</sub>(elevation)
        <br />
        &nbsp;&nbsp;+ &Delta;L<sub>type</sub>
        <br />
        &nbsp;&nbsp;+ A<sub>speed</sub>(V)
        <br />
        &nbsp;&nbsp;clamped to [20, 110]&nbsp;dBA
      </div>

      <h3 className="text-md font-medium mt-4">Caveats</h3>
      <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
        Reference levels carry an inherent <strong>&plusmn;5&nbsp;dB uncertainty</strong>, and
        a perceptual anchor to ~60 dBA at closest approach implies a +10 dB model offset over
        the spec's conservative starting value. See{' '}
        <code className="bg-slate-100 dark:bg-slate-700 px-1 rounded">SPEC.md</code> §7 for
        the full breakdown.
      </p>
    </section>
  );
}

function StatisticsSection() {
  return (
    <section className="card space-y-3">
      <h2 className="text-lg font-semibold">Statistics</h2>
      <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
        The{' '}
        <Link to="/stats" className="text-blue-600 dark:text-blue-400 hover:underline">
          Statistics
        </Link>{' '}
        page provides:
      </p>
      <ul className="list-disc list-inside text-slate-700 dark:text-slate-300 space-y-1">
        <li>Daily, weekly, monthly, and all-time flight counts.</li>
        <li>Runway usage breakdown (11/29 vs 16/34 vs unknown).</li>
        <li>Trend charts over configurable time windows (7&ndash;90 days).</li>
        <li>Hourly distribution of flights for any given day.</li>
      </ul>
    </section>
  );
}

function TechnicalNotesSection() {
  return (
    <section className="card space-y-3">
      <h2 className="text-lg font-semibold">Technical Notes</h2>
      <ul className="list-disc list-inside text-slate-700 dark:text-slate-300 space-y-1">
        <li>Built with PHP 8.3, MariaDB 10.11, and React 18.</li>
        <li>Live flight data is polled from OpenSky every 60 seconds.</li>
        <li>
          Please access the project repo for further details:{' '}
          <a
            href="https://github.com/martinker76/flightnoisetracker"
            target="_blank"
            rel="noopener noreferrer"
            className="text-blue-600 dark:text-blue-400 hover:underline"
          >
            github.com/martinker76/flightnoisetracker
          </a>
          .
        </li>
        <li>This is a public, open-access application (no login required).</li>
      </ul>
    </section>
  );
}

function ImprintSection() {
  return (
    <section className="card space-y-4" id="imprint">
      <h2 className="text-lg font-semibold">Imprint (Impressum)</h2>
      <p className="text-sm text-slate-600 dark:text-slate-400">
        Pursuant to §5 of the Austrian E-Commerce Act (E-Commerce-Gesetz, ECG):
      </p>

      <address className="not-italic space-y-1 text-slate-800 dark:text-slate-200">
        <div className="font-semibold">Martin Kersch</div>
        <div>Perlmooserweg 5</div>
        <div>2452 Mannersdorf am Leithagebirge</div>
        <div>Austria</div>
      </address>

      <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-sm">
        <dt className="font-semibold">Legal form:</dt>
        <dd>Private individual (Privatperson)</dd>

        <dt className="font-semibold">Activity:</dt>
        <dd>
          Non-commercial publication of aircraft tracking data for a private local-interest
          project. No transactions, no advertising, no paid services.
        </dd>

        <dt className="font-semibold">Competent authority:</dt>
        <dd>
          Bezirkshauptmannschaft Bruck an der Leitha (district administrative authority for
          postal code 2452).
        </dd>

        <dt className="font-semibold">No commercial register entry applies.</dt>
        <dd>This project is operated as a private hobby project.</dd>

        <dt className="font-semibold">VAT (USt):</dt>
        <dd>Not applicable (no commercial activity).</dd>
      </dl>

      <p className="text-xs text-slate-500 dark:text-slate-400">
        Liability for content: As a private publisher, I am responsible for my own content
        according to §18 (1) ECG. Links to external pages are not under my control; the
        respective operators carry responsibility for their content (§18 (2) ECG).
      </p>
    </section>
  );
}

function ContactSection() {
  const [state, setState] = useState<SubmitState>({ kind: 'idle' });

  async function handleSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const form = e.currentTarget;
    const data = new FormData(form);
    const payload = {
      name: String(data.get('name') ?? ''),
      email: String(data.get('email') ?? ''),
      subject: String(data.get('subject') ?? ''),
      message: String(data.get('message') ?? ''),
    };
    setState({ kind: 'submitting' });
    try {
      const res = await api.contact.create(payload);
      setState({ kind: 'ok', id: res.data.id });
      form.reset();
    } catch (err) {
      setState({
        kind: 'err',
        message: err instanceof Error ? err.message : 'Unknown error',
      });
    }
  }

  // The literal email address is split across part variables and joined only after
  // the component mounts (see EmailCloak). The same trick here for textual context.
  return (
    <section className="card space-y-4" id="contact">
      <h2 className="text-lg font-semibold">Contact</h2>

      <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
        The fastest way to reach the operator is the contact form below. Submissions are
        stored server-side and forwarded to the operator's email inbox. There's also a
        direct email link if you prefer that channel &mdash; it's rendered client-side from
        split parts to keep scrapers from picking it up.
      </p>

      <p className="text-sm text-slate-600 dark:text-slate-400">
        Email:{' '}
        <EmailCloak local="flightnoisetracker" domain="kersch.at" />
      </p>

      <form
        onSubmit={handleSubmit}
        className="space-y-4 border-t border-slate-200 dark:border-slate-700 pt-4"
      >
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <label className="block">
            <span className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
              Your name <span className="text-red-500">*</span>
            </span>
            <input
              name="name"
              type="text"
              required
              maxLength={120}
              autoComplete="name"
              className="w-full rounded-lg border border-slate-300 dark:border-slate-600
                         bg-white dark:bg-slate-800 px-3 py-2 text-sm
                         focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </label>
          <label className="block">
            <span className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
              Your email <span className="text-red-500">*</span>
            </span>
            <input
              name="email"
              type="email"
              required
              maxLength={254}
              autoComplete="email"
              className="w-full rounded-lg border border-slate-300 dark:border-slate-600
                         bg-white dark:bg-slate-800 px-3 py-2 text-sm
                         focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </label>
        </div>

        <label className="block">
          <span className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
            Subject <span className="text-red-500">*</span>
          </span>
          <input
            name="subject"
            type="text"
            required
            maxLength={200}
            className="w-full rounded-lg border border-slate-300 dark:border-slate-600
                       bg-white dark:bg-slate-800 px-3 py-2 text-sm
                       focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </label>

        <label className="block">
          <span className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
            Message <span className="text-red-500">*</span>
          </span>
          <textarea
            name="message"
            required
            minLength={10}
            maxLength={5000}
            rows={6}
            className="w-full rounded-lg border border-slate-300 dark:border-slate-600
                       bg-white dark:bg-slate-800 px-3 py-2 text-sm font-mono
                       focus:outline-none focus:ring-2 focus:ring-blue-500"
            placeholder="Please keep messages relevant to the project. Replies usually within a few days."
          />
          <span className="block text-xs text-slate-500 dark:text-slate-400 mt-1">
            10-5000 characters
          </span>
        </label>

        <div className="flex items-center justify-between">
          <p className="text-xs text-slate-500 dark:text-slate-400">
            Rate-limited to 5 messages per hour per IP. We log IP address and timestamp for
            spam mitigation.
          </p>
          <button
            type="submit"
            disabled={state.kind === 'submitting'}
            className="px-4 py-2 rounded-lg bg-blue-600 text-white font-medium
                       hover:bg-blue-700 disabled:bg-slate-400 disabled:cursor-not-allowed
                       focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
          >
            {state.kind === 'submitting' ? 'Sending…' : 'Send'}
          </button>
        </div>

        {state.kind === 'ok' && (
          <div
            role="status"
            className="rounded-lg bg-green-50 dark:bg-green-900/30 border border-green-200
                       dark:border-green-800 px-4 py-3 text-sm text-green-800 dark:text-green-200"
          >
            ✓ Message received (ref #{state.id}). The operator will reply within a few days.
          </div>
        )}

        {state.kind === 'err' && (
          <div
            role="alert"
            className="rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200
                       dark:border-red-800 px-4 py-3 text-sm text-red-800 dark:text-red-200"
          >
            ✗ {state.message}
            <br />
            <span className="text-xs">
              If this keeps happening, send your message directly to{' '}
              <EmailCloak local="flightnoisetracker" domain="kersch.at" />
              .
            </span>
          </div>
        )}
      </form>
    </section>
  );
}
