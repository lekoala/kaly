# Benchmarks

> See how fast this is

## Versus Lumen

In order to make fair comparisons, we decided to make our tests against a more representative app than a plain "hello world".

We used a sample app as describe in [this article](https://loige.co/developing-a-web-application-with-lumen-and-mysql/) that
show a quote pulled from the database :
- the request is routed
- a database call is made (no cache) using Eloquent
- a template is rendered (and cached) using Twig (Kaly) or Blade (Lumen)
- the response is served

Tests are made using West Wind Web Surge on a local server. Actual req/s are not representative, only relatives values make sense.

Let's see how we perform

| Item         | Req/s     | Notes                   |
|--------------|-----------|-------------------------|
| Lumen        | 57        | 6 failed requests       |
| Kaly         | 68        |                         |

Both apps have `APP_DEBUG=false`. Surprisingly, some requests are failing for Lumen for some unknown reasons.

You can serve 1.2 times more requests with Kaly on average :-)

## Using RoadRunner

You might be wondering what is the impact of using RoadRunner vs a regular setup.

Using a similar setup as the one described above, here is what we get.

| Item         | Req/s     | Notes                                     |
|--------------|-----------|-------------------------------------------|
| Kaly         | 68        |                                           |
| Kaly + RR    | 947       | We can also serve static assets if needed |

You can serve 7.1 times more requests with Kaly using RoadRunner on average.
