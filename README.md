Massive Damage's PHP-based Damage Engine framework. Now open-sourced, for your enjoyment.


## Introduction

The Damage Engine is a PHP-based framework for building complex web apps. It uses PHP's metaprogramming
systems to bring a lot of Ruby-isms to PHP. And the base environment brings with it a lot of useful error 
handling and debugging tools that make PHP a lot easier to deal with. The engine is designed to make it
a lot easier to build a pervasively-cached, database-driven web application in PHP. We've used it to build
our MMORPGs and a client's social networking site, all with much higher programmer productivity and with 
a much clearer separation of concerns than you'd get in raw PHP, or with a lot of common MVC frameworks.

That all said, the framework is far from perfect. It was built iteratively, to underpin an existing code 
base (ie. to give its benefits to old code without the need to rewrite that code). As a result, some of the 
design decisions may seem a bit odd. Further, the code is largely undocumented, as of this moment, and 
doesn't have a test suite to validate its operation. That said, we've been running this framework in 
production systems for several years now. It works.

At the moment, the best place to start is in the `docs` directory, where you will find a crash course in the
major structures of the environment. And, over the coming weeks, we'll be building some real documentation
for the system here, as we assemble version 2 of the framework (we'll be improving some class names, 
modernizing the database layer, and correcting one or two design flaws).



