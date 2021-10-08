# Benchmarks

> See how fast this is

## Versus Lumen

You can expect similar performances that if you were using Lumen.

In order to make fair comparisons, we decided to make our tests against a more representative app than a plain "hello world".

We used a sample app as describe in [this article](https://loige.co/developing-a-web-application-with-lumen-and-mysql/) that
show a quote pulled from the database :
- the request is routed
- a database call is made (no cache) using Eloquent
- a template is rendered (and cached) using Twig or Plates (Kaly) or Blade (Lumen)
- the response is served

Actual req/s are not representative, only relatives values make sense.

We've included:
- the baseline (a plain hello world script)
- a basic hello world returned from a route handler
- the full template rendering

### Tests with West Wind Web Surge

Tests are made using West Wind Web Surge on our local server. 

Let's see how we perform

| Item                  | Req/s     | Notes                   |
|-----------------------|-----------|-------------------------|
| Baseline              | 2332      | Plain hello world       |
| Lumen                 | 462       | Plain hello world       |
| Kaly                  | 458       | Plain hello world       |
| Lumen                 | 124       | 2 failed requests       |
| Kaly                  | 117       |                         |
| Kaly                  | 124       | Using plates            |

Both apps have `APP_DEBUG=false`. Surprisingly, some requests are failing for Lumen for some unknown reasons.

Note: when using a less optimized stack (no opcache, xdebug on, Lumen is about 1.2 slower).

### Tests with Apache Bench

Using ab -n 1000 -c 100 as parameters

| Item                  | Req/s     | Notes                   |
|-----------------------|-----------|-------------------------|
| Baseline              | 2680      | Plain hello world       |
| Lumen                 | 1015      | Plain hello world       |
| Kaly                  | 1028      | Plain hello world       |
| Lumen                 | 572       | 64 failed requests      |
| Kaly                  | 434       |                         |
| Kaly                  | 495       | Using plates            |

These results are consistent with what we can see with West Wind Web Surge.

Still not sure where these failed request come from, probably some stateful process that gets in the way :-)

## Using RoadRunner

You might be wondering what is the impact of using RoadRunner vs a regular setup.

Using a similar setup as the one described above, here is what we get.

| Item         | Req/s     | Notes                                     |
|--------------|-----------|-------------------------------------------|
| Baseline     | 2332      |                                           |
| Kaly         | 124       |                                           |
| Kaly + RR    | 947       | We can also serve static assets if needed |
| Kaly + RR    | 1897      | Plain hello world                         |

Using RoadRunner gives 5 to 10 times more req/s on average, which is impressive.

When serving simple responses, there is almost no overhead compared to a plain php script.
