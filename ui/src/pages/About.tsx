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
        <h2 className="text-lg font-semibold">Estimated Noise Levels</h2>
        <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
          Each flight includes an <strong>estimated peak noise level</strong> (L<sub>Amax</sub>, in dBA)
          calculated at the Mannersdorf town center. The model is the v1.1 multi-component
          aircraft noise estimator described in <code className="bg-slate-100 dark:bg-slate-700 px-1 rounded">SPEC-NOISE-MODEL-v1.1.md</code>{' '}
          (in the project repository). It accounts for the dominant physical effects that determine
          how loud an aircraft sounds on the ground.
        </p>

        <div className="bg-slate-50 dark:bg-slate-700/50 rounded-lg p-4 font-mono text-xs overflow-x-auto">
          L<sub>Amax</sub> = L<sub>ref</sub>[phase,cat] + offset
          <br />&nbsp;&nbsp;&minus; 20 &middot; log<sub>10</sub>(d<sub>slant</sub> / 1&nbsp;km)
          <br />&nbsp;&nbsp;&minus; &alpha;(phase) &middot; d<sub>slant</sub>
          <br />&nbsp;&nbsp;&minus; A<sub>ground</sub>
          <br />&nbsp;&nbsp;&minus; A<sub>screening</sub>(elevation)
          <br />&nbsp;&nbsp;+ &Delta;L<sub>type</sub>
          <br />&nbsp;&nbsp;+ A<sub>speed</sub>(V)
          <br />&nbsp;&nbsp;clamped to [20, 110]&nbsp;dBA
        </div>

        <h3 className="text-md font-medium mt-4">What the components mean</h3>
        <ul className="list-disc list-inside text-slate-700 dark:text-slate-300 space-y-1">
          <li>
            <strong>L<sub>ref</sub>[phase,cat]</strong> &mdash; reference noise level (dBA at 1&nbsp;km
            slant, free-field, idle thrust) for one of six flight phases (CLIMBOUT, APPROACH
            TRANSITION, FINAL APPROACH, plus taxi/ground and go-around) crossed with one of six
            aircraft categories (Heavy widebody, Heavy narrowbody, Medium narrowbody &mdash; the
            A320/B737 reference &mdash; Regional jet, Turboprop, Light piston/turboprop).
          </li>
          <li>
            <strong>Geometric spreading</strong> &mdash; 6&nbsp;dB loss per doubling of distance
            (20&middot;log<sub>10</sub>).
          </li>
          <li>
            <strong>Atmospheric absorption</strong> &mdash; phase-dependent &alpha; from ISO&nbsp;9613-1:
            5&nbsp;dB/km (climbout), 6&nbsp;dB/km (approach transition), 8&nbsp;dB/km (final
            approach). A-weighted aircraft noise peaks in the 1&ndash;2&nbsp;kHz range where
            absorption is significant.
          </li>
          <li>
            <strong>Ground reflection &amp; screening</strong> &mdash; up to 2.5&nbsp;dB boost from
            soft-ground reflection; up to 2&nbsp;dB screening loss when the aircraft is near the
            horizon (elevation &lt; ~17&deg;).
          </li>
          <li>
            <strong>Aircraft-type correction (&Delta;L<sub>type</sub>)</strong> &mdash; additive offset
            relative to A320/B737 baseline: A220 &minus;2&nbsp;dB, A380 +8&nbsp;dB, Q400 +6&nbsp;dB,
            etc. Covers engine type, nacelle treatment, and airframe noise differences.
          </li>
          <li>
            <strong>Speed correction (A<sub>speed</sub>)</strong> &mdash; +7&middot;10&middot;log<sub>10</sub>(V/V<sub>ref</sub>)
            for V &gt; V<sub>ref</sub> (faster aircraft are louder). Falls back to baseline above
            250&nbsp;m/s.
          </li>
        </ul>

        <h3 className="text-md font-medium mt-4">Calibration</h3>
        <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
          Reference levels carry an inherent <strong>&plusmn;5&nbsp;dB uncertainty</strong> &mdash;
          they are back-derived from ICAO certification data with an EPNdB&rarr;L<sub>Amax</sub>{' '}
          conversion that is uncertain to a few&nbsp;dB. To anchor the model to reality at this
          site, the model output is compared against the developer's{' '}
          <strong>subjective perception</strong> of typical VIE approach flights at Mannersdorf,
          which is consistently around <strong>~60&nbsp;dBA</strong> at closest approach.
        </p>
        <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
          The uncalibrated v1.1 worked example (A320 approach at 2&nbsp;km slant, 450&nbsp;m
          altitude) produces ~47&nbsp;dBA; the perceptual anchor implies an offset of roughly
          <strong> +13&nbsp;dB</strong>. The deployed model therefore starts with{' '}
          <code className="bg-slate-100 dark:bg-slate-700 px-1 rounded">l_ref_offset_db = +10</code>{' '}
          (a compromise between the spec's conservative +5 and the perceptual +13) until a
          Class&nbsp;1 sound-level meter is installed for a formal calibration pass. The offset is
          a single config parameter (<code className="bg-slate-100 dark:bg-slate-700 px-1 rounded">config/app.php</code>)
          and can be re-tuned without rebuilding.
        </p>

        <h3 className="text-md font-medium mt-4">Caveats</h3>
        <ul className="list-disc list-inside text-slate-700 dark:text-slate-300 space-y-1">
          <li>
            <strong>Polling cadence:</strong> OpenSky samples every 60&nbsp;s, so the model may miss
            the true peak of a fast flyover. Estimates use the loudest sampled position.
          </li>
          <li>
            <strong>Weather:</strong> wind, temperature inversions, and humidity all affect actual
            propagation but are not modeled in real time. ISA defaults with seasonal adjustment
            only.
          </li>
          <li>
            <strong>Terrain:</strong> the Leithagebirge ridge south of Mannersdorf provides some
            terrain shielding for southerly arrivals; this is not yet modeled.
          </li>
          <li>
            <strong>Calibration pending:</strong> absolute values carry ~&plusmn;5&nbsp;dB
            uncertainty until a sound meter validates the offset. Relative comparisons between
            flights are robust regardless.
          </li>
        </ul>
      </section>

      <section className="card space-y-3">
        <h2 className="text-lg font-semibold">Aircraft Type Resolution</h2>
        <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
          Aircraft types are resolved from the{' '}
          <a
            href="https://adsb.lol"
            target="_blank"
            rel="noopener noreferrer"
            className="text-blue-600 dark:text-blue-400 hover:underline"
          >
            ADSB.lol
          </a>{' '}
          public API using the aircraft's ICAO 24-bit transponder address (icao24). Each new flight
          triggers a lookup to resolve the ICAO type code (e.g., B738 for Boeing 737-800, A320 for
          Airbus A320). Results are cached per polling cycle to minimize API calls.
        </p>
        <p className="text-slate-700 dark:text-slate-300 leading-relaxed">
          The type distribution is shown on the Statistics page, giving insight into what kinds of
          aircraft typically overfly Mannersdorf.
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
