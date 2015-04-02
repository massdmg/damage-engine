# Damage Engine Copyright 2012-2015 Massive Damage, Inc.
#
# Licensed under the Apache License, Version 2.0 (the "License"); you may not use this file except 
# in compliance with the License. You may obtain a copy of the License at
#
#     http://www.apache.org/licenses/LICENSE-2.0
#
# Unless required by applicable law or agreed to in writing, software distributed under the License 
# is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express 
# or implied. See the License for the specific language governing permissions and limitations under 
# the License.

#
# Load the Reactive Coffee framework

bind = rx.bind
rxt = rx.rxt
rxt.importTags()
_.mixin(_.str.exports())


#
# You will need exactly one Application (generally, window.app) to control your
# application. Define layouts, handlers, routing, etc. here.

class window.Application
  
  constructor: (definer = null) ->
    @routing    = new RouteNode("", null)
    @variables  = {}
    @template   = rx.cell(@messageInitializing)
    @parameters = []
    @page       = null
    @sections   = rx.array()

    definer?(this)
    
    rx.transaction =>
      @template.onSet.sub ([old, template]) =>
        viewport = $("#app");
        viewport.empty();
        if template 
          viewport.append(@messageLoading());
          contents = template(_({app: this}).defaults(@variables))
          viewport.empty()
          viewport.append(contents)
        else
          viewport.append(@messageNotFound())
    
  delegate: (url, handlerName, sections = []) ->
    if !_.isArray(sections) then sections = [sections]
    path = url.split('/').slice(1)
    @routing.addRoute(path, handlerName, sections)
    
  set: (name, value) ->
    if @variables[name]?
      @variables[name].set(value) 
    else 
      @variables[name] = if value instanceof rx.ObsCell then value else rx.cell(value)
    
  get: (name) ->
    @variables[name]?.get()
    
  load: (pieces...) -> 
    url = pieces.join("/")
    url = url.slice(1) if url.indexOf('#') == 0
    url = "/#{url}" unless url.indexOf('/') == 0
    window.location.hash = url
    div ""
    
  inSection: (section) ->
    @sections.all().indexOf(section) > -1
    
  start: ->
    $(window).bind    "hashchange", @route
    $(window).trigger "hashchange"
    
  
  route: (event) =>
    rx.transaction =>
      window.location.hash = "/" if window.location.hash == "" or window.location.hash == "#"
      path       = ("" + window.location.hash).split('/').slice(1)
      solution   = []
      parameters = {}
      sections   = []

      if target = @routing.route(path, parameters, solution, sections)
        @sections.replace(sections)
        if _.isString(target)

          #
          # If target is a string, it's a redirect; don't touch the variable scope
          
          pieces = target.split("/").map (step) ->
            if step.substr(0, 1) == ":" and parameters[step.substr(1)]?
              parameters[step.substr(1)]
            else
              step
          window.location.hash = pieces.join("/")

        else         
        
          #
          # We are routing. Start by unwinding the current URL variable scope to the common point.
          # If we are changing templates, unwind it all and start over. This ensures exiting content 
          # doesn't cling to the application.
        
          unwind = @page?.solution ? []
          if @page and @template.get() == target
            old     = @page.solution
            common  = []
            unwind  = []
            highest = _.min(old.length, solution.length)
            for i in [highest..1]
              j = i - 1
              if _.isEqual(old[0..j], solution[0..j]) 
                common = solution[0..j]
                unwind = old[i..]
                break
          for symbol in unwind.reverse()
            if symbol.slice(0, 1) == ':'
              name = symbol.slice(1)
              @variables[name].disconnect?()
              delete @variables[name]

          #
          # Now set up the new variable scope and launch the template.
        
          for own name, value of parameters
            @variables[name] ?= rx.cell()
            @variables[name].set(value)
          
          @page = 
            url       : "/" + path.join("/")
            address   : "/" + solution.join("/")
            solution  : solution
            template  : target
            parameters: parameters
            
          @template.set(target)
      else
        @template.set(@messageNotFound)

  messageNotFound: (opts = {}) ->
    p {class: 'text-danger'}, [i "No applicable template found. Please contact support."]
  
  messageInitializing: (opts = {}) ->
    p {class: 'text-info pulsate'}, [i "Initializing..."]
      
  messageLoading: (opts = {}) ->
    p {class: 'text-info pulsate'}, [i "Loading..."]



#
# Defines an available API service. parameters is name: default value (use 0 or "" for a cell, [] for array) => "cell" or "array"

class window.Service
  constructor: (@method, @url, @parameters) ->
    
  specialize: (parameters = {}) ->
    new Service(@method, @url, _(parameters).defaults(@parameters))

  instantiate: (parameters = {}) ->
    new ServiceCall(this, _(parameters).defaults(@parameters))
    
  load: (parameters = {}) ->
    @instantiate(parameters).load()

  call: (parameters = {}) ->
    @load(parameters)
    

class ServiceCall
  constructor: (@service, parameters = {}) ->
    @parameters = {}
    @promise    = null
    @status     = rx.cell("loading")
    @code       = rx.cell(0)
    @response   = rx.cell(null)
    @content    = rx.cell(null)
    
    for own key, value of parameters
      @parameters[key] = if value.get? then value.get() else value
    
    @doneHandler = (data, code) =>
      response = data.response ? {}
      # console.log(@service.url + " responded:")
      # console.log(response)
      
      rx.transaction =>
        @code.set(code)
        @response.set(response)
        
        if response.success? and response.success
          @status.set("success")
          @content.set(response.content ? {})
        else if response.message?
          @status.set("failed")
          @content.set(response.message)
          # console.log(response)
        else
          @status.set("failed")
          @content.set(code)
          # console.log(data)
    
    @failHandler = (xhr, code) =>
      rx.transaction =>
        @code.set(code)
        @response.set(null)
        @status.set("failed")
        @content.set(code)
    

  call: (parameters = {}) ->
    @load(parameters)
    
  load: (parameters = {}) ->
    for own key, value of parameters
      @parameters[key] = if value.get? then value.get() else value
    
    @promise = $.ajax(@service.url, {type: @service.method.toUpperCase(), cache: false, headers: { "cache-control": "no-cache" }, context: this, data: @parameters, dateType: "json"}).done(@doneHandler).fail(@failHandler)
    this
      
  reset: () ->
    @code.set(0)
    @status.set("loading")
    @response.set(null)
    @content.set(null)
    

  done: (handler) ->
    @response.onSet.sub ([oldValue, newValue]) =>
      if newValue
        handler(newValue, this)
      
    this
    
  success: (handler) ->
    @status.onSet.sub ([oldValue, newValue]) =>
      if newValue == "success"
        handler(@content.get(), @response.get(), this)
        
    this
        
  fail: (handler) ->
    @status.onSet.sub ([oldValue, newValue]) =>
      if newValue == "failed"
        handler(@content.get(), @response.get(), this)
        
    this
    
   

#
# RouteNode is used internally to manage routing of URLs to the correct handler.
# Use the methods on Application instead.

class RouteNode
  constructor: (name, markers = []) ->
    @name      = name
    @target    = null
    @names     = {}
    @parameter = null
    @markers   = markers
        
  setTarget: (handler) ->
    @target = handler
  
  addRoute: (path, handler, markers) ->
    [name, rest...] = path

    route = null
    if name.substr(0, 1) == ":"
      if @parameter
        if @parameter.name != name.substr(1)
          throw "can't route two different variable parameters"
        else
          route = @parameter
      else
        @parameter = route = new RouteNode(name.substr(1), markers)
    else if @names[name] 
      route = @names[name] 
    else
      @names[name] = route = new RouteNode(name, markers)

    if rest.length == 0
      route.setTarget(handler)
    else
      route.addRoute(rest, handler, markers)
      
  route: (path, parameters = {}, symbols = [], markers = []) ->
    if path.length == 0
      for index, marker of @markers
        markers.push(marker)
      @target
    else
      [name, rest...] = path
      if @names[name]
        symbols.push(name)
        @names[name].route(rest, parameters, symbols, markers)
      else if @parameter
        parameters[@parameter.name] = decodeURIComponent(name)
        symbols.push(":#{@parameter.name}")
        @parameter.route(rest, parameters, symbols, markers)
      else
        null

