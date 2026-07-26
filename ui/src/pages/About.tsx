import { Link } from 'react-router-dom';

const BOX_CENTER: [number, number] = [47.974, 16.604];

export default function About() {
  return (
    <div className="space-y-6 max-w-3xl">
      <h1 className="text-2xl font-bold">ℹ️ About FlightNoiseTracker</h1>

      <section className="card space-y-3">
        <h2 className="text-lg font-semibold">Purpose</h2>
        <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
          FlightNoiseTracker monitors aircraft movements over{' '}
          <strong>Mannersdorf am Leithagebirge (2452)</strong>, Lower Austria,
          and answers one core question:
        </p>
        <blockquote className="border-l-4 border-blue-500 pl-4 italic text-slate-600 dark:text-slate-400">
          Is the number of flights routed across the city increasing, and are
          noise emissions growing as a result?
        </blockquote>
        <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
          The app captures every flight passing through Mannersdorf's airspace,
          classifies which runway at Vienna International Airport (LOWW / VIE)
          each flight is using, and provides daily and hourly statistics to
          track trends over time.
        </p>
      </section>

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
          , a community-driven ADS-B receiver network that aggregates air
          traffic surveillance data from thousands of volunteers worldwide.
        </p>
        <ul className="list-disc list-inside text-slate-700 dark:text-slate-300 space-y-1">
          <li>
            <strong>Live data</strong> &mdash; polled every 60 seconds from
            OpenSky's <code className="bg-slate-100 dark:bg-slate-700 px-1 rounded">/states/all</code>{' '}
            endpoint, filtered to the Mannersdorf bounding box.
          </li>
          <li>
            <strong>Historical data</strong> &mdash; fetched from OpenSky's
            arrival/departure and track endpoints to backfill past weeks of
            traffic.
          </li>
          <li>
            <strong>Aircraft metadata</strong> &mdash; type codes, models, and
            operator information are sourced from the{' '}
            <a
              href="https://openflights.org/data.html"
              target="_blank"
              rel="noopener noreferrer"
              className="text-blue-600 dark:text-blue-400 hover:underline"
            >
              OpenFlights
            </a>{' '}
            aircraft database.
          </li>
        </ul>
      </section>

      <section className="card space-y-3">
        <h2 className="text-lg font-semibold">Monitored Area</h2>
        <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
          The monitored airspace is a rectangle centered on Mannersdorf, sized
          to cover the built-up area plus a buffer where aircraft are clearly
          audible.
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
            <p className="font-mono text-sm">{BOX_CENTER[0]}°N, {BOX_CENTER[1]}°E</p>
          </div>
          <div>
            <p className="text-xs text-slate-500 uppercase tracking-wider mb-1">Area</p>
            <p className="font-mono text-sm">~6 km × 5 km</p>
          </div>
        </div>

        <h3 className="text-md font-medium mt-4">How the boundaries were chosen</h3>
        <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
          The box is based on <strong>audibility</strong>, not administrative
          borders. A typical jet on approach to Vienna (A320 / B737) passes over
          the area at roughly <strong>1,200&ndash;1,800 m</strong> altitude. At
          the box's edge, the slant distance is approximately{' '}
          <strong>3 km</strong>, producing an estimated{' '}
          <strong>~55 dBA</strong> at ground level &mdash; clearly audible
          above daytime background noise (~40&ndash;45 dBA). The box extends
          about halfway toward neighboring villages (Hof am Leithaberge to the
          SW, Sommerein to the NE, Götzendorf to the N) without reaching their
          built-up areas.
        </p>
      </section>

      <section className="card space-y-3">
        <h2 className="text-lg font-semibold">Reference Point</h2>
        <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
          The center of Mannersdorf resolves to{' '}
          <strong>Mannersdorfer Schloss</strong> (the old castle) at{' '}
          <strong>Hauptstraße 48</strong> &mdash; OSM's centroid for the town.
          The app reports the <em>closest distance</em> each flight passes to
          this point, calculated from every position sample collected while the
          aircraft was within the bounding box.
        </p>
      </section>

      <section className="card space-y-3">
        <h2 className="text-lg font-semibold">Airport &amp; Runway Classification</h2>
        <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
          The app focuses exclusively on{' '}
          <strong>Vienna International Airport (LOWW / VIE)</strong>
          (48.1103°N, 16.5697°E, ~20 km north of Mannersdorf). Every flight is
          classified as:
        </p>
        <ul className="list-disc list-inside text-slate-700 dark:text-slate-300 space-y-1">
          <li>
            <strong>VIE-related</strong> &mdash; the flight passed within 50 km
            of LOWW at an altitude below 6,000 m, suggesting an arrival or
            departure.
          </li>
          <li>
            <strong>Overflight</strong> &mdash; the flight crossed the bounding
            box without approaching Vienna Airport, e.g. en-route traffic at
            cruise altitude.
          </li>
        </ul>
        <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
          For VIE-related flights, the runway is inferred from the aircraft's
          heading, altitude, and position at its closest approach to the
          airport:
        </p>
        <ul className="list-disc list-inside text-slate-700 dark:text-slate-300 space-y-1">
          <li>
            <strong>Runway 11/29</strong> &mdash; approach along a ~110° or
            ~290° heading (ESE&ndash;WNW alignment, 3,500 m).
          </li>
          <li>
            <strong>Runway 16/34</strong> &mdash; approach along a ~160° or
            ~340° heading (NNW&ndash;SSE alignment, 3,600 m).
          </li>
        </ul>
      </section>

      <section className="card space-y-3">
        <h2 className="text-lg font-semibold">Noise Tracking</h2>
        <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
          Manual noise observations can be logged through the{' '}
          <Link
            to="/noise"
            className="text-blue-600 dark:text-blue-400 hover:underline"
          >
            Noise Log
          </Link>{' '}
          page &mdash; noting the decibel level, time, and optional location.
          These entries are linked to flights that were passing through the
          bounding box at the recorded time, helping correlate noise events with
          specific aircraft and runway usage.
        </p>
      </section>

      <section className="card space-y-3">
        <h2 className="text-lg font-semibold">Statistics &amp; Trends</h2>
        <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
          The{' '}
          <Link
            to="/stats"
            className="text-blue-600 dark:text-blue-400 hover:underline"
          >
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

      <section className="card space-y-3">
        <h2 className="text-lg font-semibold">Technical Notes</h2>
        <ul className="list-disc list-inside text-slate-700 dark:text-slate-300 space-y-1">
          <li>Built with PHP 8.3, MariaDB 10.11, and React 18.</li>
          <li>Live flight data is polled from OpenSky every 60 seconds.</li>
          <li>
            The bounding box, airport coordinates, and classification parameters
            are configured server-side and can be adjusted without rebuilding
            the frontend.
          </li>
          <li>This is a public, open-access application (no login required).</li>
        </ul>
      </section>
    </div>
  );
}
