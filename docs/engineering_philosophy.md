# Development Philosophy

### Primary Principles

**#KISS (Keep It Simple, Stupid)**: Choose the simplest solution that solves the problem.

- Applied throughout any codebase by eliminating unnecessary patterns and consolidating redundant abstractions
- Guides all architectural decisions - simplicity over cleverness

**#DRY (Don't Repeat Yourself)**: Every piece of knowledge should have a single, unambiguous representation.

- Implemented through logical organization with shared services and utilities
- Type-safe definitions centralized where appropriate

**#YAGNI (You Aren't Gonna Need It)**: Don't add functionality until it's actually needed.

- Prevents over-engineering and speculative features
- Focus on current requirements rather than imaginary futures

**#BestCodeIsNoCode**: Every line of code is a liability. Less code = fewer bugs.

- Drives architectural simplification and complexity reduction
- Preference for removing code over adding abstractions

### Supporting Rules

**#RuleOfThree**: Wait until you've written something 3 times before abstracting it.

- Prevents premature abstraction and over-engineering
- Ensures abstractions are truly needed and well-understood

**#SimplestThingFirst**: Start with the obvious solution. Optimize later if needed.

- Guides implementation approach - make it work, then make it right, then make it fast
- Most code never needs the third step

**#MakeItWork → #MakeItRight → #MakeItFast**: In that order.

- Clear prioritization of effort and focus
- Performance optimization only after correctness is established

**#TwoWeekTest**: If you won't understand your code after 2 weeks away, simplify it.

- Ensures maintainable code that doesn't require constant context
- Drives clear, self-documenting implementations

**#BoringTechnology**: Use proven, stable, well-documented tools.

- Technology choices prioritize predictability over novelty
- Choose tools that have been battle-tested for years

**#DeleteCodeLiberally**: Remove dead code immediately. Git remembers everything.

- Keeps codebase clean and reduces cognitive load
- No "just in case" code retention

### Anti-Patterns to Avoid

**#PrematureOptimization**: The root of all evil

- Focus on correctness and simplicity before performance
- Measure before optimizing

**#SpeculativeGenerality**: Building for imaginary futures

- Don't build features or abstractions for use cases that don't exist
- Address current requirements with simple solutions

**#OverAbstraction**: Creating unnecessary layers

- Avoid abstractions that don't provide clear value
- Direct solutions preferred over complex inheritance hierarchies

**#PatternObsession**: Using design patterns where they don't belong

- Design patterns are tools, not requirements
- Use patterns only when they solve actual problems

### The Golden Rule

**#FewerMovingParts**: When in doubt, choose the solution with fewer moving parts.

- The ultimate decision-making criterion when multiple solutions exist
- Directly responsible for successful architectural simplifications
- Applied to technology choices, component design, and system architecture

## Implementation Guidelines

These principles should be evident throughout any codebase:

1. **Simplified Architecture**: Elimination of unnecessary patterns and abstractions
2. **Logical Organization**: Clear, consistent structure based on features or domains
3. **Technology Choices**: Proven stack with minimal configuration complexity
4. **Component Design**: Simple, direct implementations without over-engineering
5. **State Management**: Clear layers without complex middleware or unnecessary side effects

## Development Decision Framework

When making any development decision, apply these rules in order:

1. **#FewerMovingParts** - Which solution has fewer components/dependencies/layers?
2. **#KISS** - What's the simplest way to solve this problem?
3. **#YAGNI** - Is this feature/abstraction actually needed now?
4. **#TwoWeekTest** - Will this be understandable in two weeks?
5. **#BoringTechnology** - Are we using proven, stable tools?

This philosophy creates maintainable, scalable code while reducing complexity and technical debt.
